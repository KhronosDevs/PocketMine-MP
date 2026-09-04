/*
 * kh_native.c — native acceleration for Khronos (PocketMine-MP 2.0.0 ECS
 * rewrite). Loaded from PHP through FFI (see src/pocketmine/core/resource/
 * NativeAccel.php) with a pure-PHP fallback when FFI or the library is
 * unavailable.
 *
 * Every function is PURE (no shared mutable state — buffers are stack/malloc
 * local), so it is safe to call from any PHP thread: the main thread (light,
 * serialize), region threads and the chunk-gen pool workers (noise).
 *
 * Each function is byte-compatible with its PHP counterpart; native/verify.php
 * asserts that on real data.
 */

#include <stdlib.h>
#include <string.h>
#include <stdint.h>
#include <math.h>

/* ------------------------------------------------------------------ */
/* Light: byte-identical to LightCalculator::calculate().              */
/* ------------------------------------------------------------------ */

/*
 * Recompute sky + block light for a whole chunk.
 *
 *   blocks[65536]  flat block-id grid (Y-major: idx = (y<<8)|(z<<4)|x)
 *   opacity[256]   per-block-id light opacity
 *   emission[256]  per-block-id light emission
 *   has_sky        0 = nether (sky light stays 0, only block BFS runs)
 *   sky_out[32768] packed sky light (16 sections x 2048)
 *   block_out[32768] packed block light
 *
 * Returns 0 on success, -1 on allocation failure.
 */
int kh_light_calc(const unsigned char *blocks,
                  const unsigned char *opacity,
                  const unsigned char *emission,
                  int has_sky,
                  unsigned char *sky_out,
                  unsigned char *block_out)
{
    unsigned char *ids = (unsigned char *)malloc(65536);
    int16_t *sky = (int16_t *)malloc(65536 * sizeof(int16_t));
    int16_t *blk = (int16_t *)malloc(65536 * sizeof(int16_t));
    int32_t *queue = (int32_t *)malloc(65536 * 2 * sizeof(int32_t));
    if (ids == NULL || sky == NULL || blk == NULL || queue == NULL) {
        free(ids); free(sky); free(blk); free(queue);
        return -1;
    }
    memcpy(ids, blocks, 65536);
    memset(sky, 0, 65536 * sizeof(int16_t));
    memset(blk, 0, 65536 * sizeof(int16_t));

    /* Sky: per-column falloff from 15 at the top, reduced by opacity. */
    if (has_sky) {
        for (int x = 0; x < 16; x++) {
            for (int z = 0; z < 16; z++) {
                int level = 15;
                for (int y = 255; y >= 0; y--) {
                    int idx = (y << 8) | (z << 4) | x;
                    sky[idx] = (int16_t)level;
                    level -= opacity[ids[idx]];
                    if (level < 0) {
                        level = 0;
                    }
                }
            }
        }
    }

    /* Block light: BFS flood from every emitter (queue stores idx, level). */
    int qh = 0, qt = 0;
    for (int i = 0; i < 65536; i++) {
        if (emission[ids[i]] > 0) {
            blk[i] = (int16_t)emission[ids[i]];
            queue[qt++] = i;
            queue[qt++] = emission[ids[i]];
        }
    }
    while (qh < qt) {
        int idx = queue[qh++];
        int level = queue[qh++];
        int x = idx & 15, z = (idx >> 4) & 15, y = idx >> 8;
#define VISIT(n) do { \
            int nx = level - 1 - opacity[ids[(n)]]; \
            if (nx > blk[(n)]) { blk[(n)] = (int16_t)nx; queue[qt++] = (n); queue[qt++] = nx; } \
        } while (0)
        if (x > 0)   VISIT(idx - 1);
        if (x < 15)  VISIT(idx + 1);
        if (z > 0)   VISIT(idx - 16);
        if (z < 15)  VISIT(idx + 16);
        if (y > 0)   VISIT(idx - 256);
        if (y < 255) VISIT(idx + 256);
#undef VISIT
    }

    /* Nibble pack: 16 sections, even index low nibble, odd high nibble. */
    for (int sy = 0; sy < 16; sy++) {
        const int16_t *sb = sky + sy * 4096;
        const int16_t *bb = blk + sy * 4096;
        unsigned char *so = sky_out + sy * 2048;
        unsigned char *bo = block_out + sy * 2048;
        for (int i = 0; i < 2048; i++) {
            so[i] = (unsigned char)(((sb[i * 2 + 1] & 0x0F) << 4) | (sb[i * 2] & 0x0F));
            bo[i] = (unsigned char)(((bb[i * 2 + 1] & 0x0F) << 4) | (bb[i * 2] & 0x0F));
        }
    }

    free(ids); free(sky); free(blk); free(queue);
    return 0;
}

/* ------------------------------------------------------------------ */
/* Noise: byte-identical to ParallelGeneratorAdapter::smoothNoise().    */
/* All intermediates are 64-bit: the PHP original uses 64-bit ints and  */
/* shift-9 cells overflow 32-bit (u*u = 4.28e9 > INT32_MAX).            */
/* ------------------------------------------------------------------ */

static int noise2d(int x, int z, int seed)
{
    seed &= 0x7FFFFFFF;
    unsigned long long n = (unsigned long long)((long long)x * 374761393LL)
                         ^ (unsigned long long)((long long)z * 668265263LL)
                         ^ (unsigned long long)((long long)seed * 1103515245LL);
    n &= 0x7FFFFFFF;
    n = (n ^ (n >> 13)) * 1274126177LL;
    n &= 0x7FFFFFFF;
    n ^= n >> 16;
    return (int)(n & 0xFFFF);
}

static int smooth_noise(int x, int z, int seed, int shift)
{
    int cell = 1 << shift;
    int gx = x >> shift, gz = z >> shift;              /* floor div (arith shift) */
    int fx = x & (cell - 1), fz = z & (cell - 1);      /* non-negative remainder  */
    int v00 = noise2d(gx, gz, seed);
    int v10 = noise2d(gx + 1, gz, seed);
    int v01 = noise2d(gx, gz + 1, seed);
    int v11 = noise2d(gx + 1, gz + 1, seed);

    /* Smoothstep weight in Q16 fixed point: w = 3u^2 - 2u^3, u = f/cell. */
    long long u = ((long long)fx * 65536) / cell;
    long long u2 = (u * u) / 65536;
    long long u3 = (u2 * u) / 65536;
    long long tx = 3 * u2 - 2 * u3;

    u = ((long long)fz * 65536) / cell;
    u2 = (u * u) / 65536;
    u3 = (u2 * u) / 65536;
    long long tz = 3 * u2 - 2 * u3;

    long long top = v00 + (((long long)v10 - v00) * tx) / 65536;
    long long bottom = v01 + (((long long)v11 - v01) * tx) / 65536;
    return (int)(top + ((bottom - top) * tz) / 65536);
}

/*
 * N octaves of smooth noise for every column of a chunk, in ONE call (a
 * per-column FFI call would pay the boundary 256 times and negate the win).
 *
 *   out[c * count + i] = smooth_noise(worldX, worldZ, seed ^ xors[i], shifts[i])
 *   where worldX = chunkX*16 + (c & 15), worldZ = chunkZ*16 + (c >> 4).
 */
void kh_noise_octaves(int chunk_x, int chunk_z, int seed,
                      const int *shifts, const int *xors, int count,
                      int *out)
{
    for (int c = 0; c < 256; c++) {
        int wx = chunk_x * 16 + (c & 15);
        int wz = chunk_z * 16 + (c >> 4);
        for (int i = 0; i < count; i++) {
            out[c * count + i] = smooth_noise(wx, wz, seed ^ xors[i], shifts[i]);
        }
    }
}

/*
 * One octave of smooth noise for every column of a chunk, with a fixed XOR
 * applied to the world x BEFORE the noise: out[c] = smooth_noise((chunk_x*16
 * + (c&15)) ^ xmask, chunk_z*16 + (c>>4), seed, shift).
 *
 * The nether cave pass feeds a per-y xor into x (the PHP loop does
 * smoothNoise($wx ^ ($y * 7919), $wz, $seed ^ 0x5B4C2A91, 5)), so each of the
 * 128 y-slices is one batched call here instead of 256 PHP smoothNoise calls.
 */
void kh_noise_columns_xor(int chunk_x, int chunk_z, int xmask, int seed,
                          int shift, int *out)
{
    for (int c = 0; c < 256; c++) {
        int wx = chunk_x * 16 + (c & 15);
        int wz = chunk_z * 16 + (c >> 4);
        out[c] = smooth_noise(wx ^ xmask, wz, seed, shift);
    }
}

/* ------------------------------------------------------------------ */
/* Serializer helpers.                                                 */
/* ------------------------------------------------------------------ */

/*
 * Nibble-pack a byte-per-block array into half the bytes (ChunkSerializer::
 * packNibbles). Returns the number of output bytes.
 */
int kh_pack_nibbles(const unsigned char *in, size_t len, unsigned char *out)
{
    size_t j = 0;
    size_t i;
    for (i = 0; i + 1 < len; i += 2) {
        out[j++] = (unsigned char)((in[i] & 0x0F) | ((in[i + 1] & 0x0F) << 4));
    }
    if (len % 2 == 1) {
        out[j++] = (unsigned char)(in[len - 1] & 0x0F);
    }
    return (int)j;
}

/*
 * Expand a vanilla nibble array into full bytes (RegionStorageAdapter::
 * unpackNibbles): even index low nibble, odd index high nibble.
 * Returns the number of output bytes.
 */
int kh_unpack_nibbles(const unsigned char *in, size_t len, unsigned char *out)
{
    size_t j = 0;
    for (size_t i = 0; i < len; i++) {
        out[j++] = (unsigned char)(in[i] & 0x0F);
        out[j++] = (unsigned char)((in[i] >> 4) & 0x0F);
    }
    return (int)j;
}

/*
 * Build the 8-section packed sky-light payload from the per-column height
 * map (ChunkSerializer::buildSkyLight): out[16384]. A block is lit when
 * worldY >= surface - 1 (surface clamped to 0..127).
 */
void kh_build_sky_light(const unsigned char *heightmap, unsigned char *out)
{
    unsigned char surface[256];
    for (int i = 0; i < 256; i++) {
        int s = (int)heightmap[i];
        if (s < 0) s = 0;
        if (s > 127) s = 127;
        surface[i] = (unsigned char)s;
    }
    for (int sy = 0; sy < 8; sy++) {
        for (int i = 0; i < 2048; i++) {
            int localY = (2 * i) >> 8;
            int colEven = (2 * i) & 255;
            int colOdd = colEven + 1;
            int worldY = sy * 16 + localY;
            unsigned char even = (worldY >= (int)surface[colEven] - 1) ? 0x0F : 0x00;
            unsigned char odd = (worldY >= (int)surface[colOdd] - 1) ? 0x0F : 0x00;
            out[sy * 2048 + i] = (unsigned char)((odd << 4) | even);
        }
    }
}

/* ------------------------------------------------------------------ */
/* Nukkit terrain: simplex noise column profiles (256 columns in ONE call).
 * Computes height + biome for every column using the Nukkit Normal
 * generator's multi-noise terrain math. Replaces ~1280 PHP noise calls.
 * ------------------------------------------------------------------ */

#define SIMP_F2 0.3660254037844386
#define SIMP_G2 0.21132486540518713

static const float GRAD2[8][2] = {
    {1,1},{-1,1},{1,-1},{-1,-1},{1,0},{-1,0},{0,1},{0,-1}
};

static float simplex_noise2d(float x, float y, const int *perm)
{
    float s = (float)((x + y) * SIMP_F2);
    int i = (int)floor(x + s);
    int j = (int)floor(y + s);
    float t = (float)((i + j) * SIMP_G2);
    float x0 = x - (i - t);
    float y0 = y - (j - t);
    int i1, j1;
    if (x0 > y0) { i1 = 1; j1 = 0; } else { i1 = 0; j1 = 1; }
    float x1 = x0 - i1 + (float)SIMP_G2;
    float y1 = y0 - j1 + (float)SIMP_G2;
    float x2 = x0 - 1.0f + 2.0f * (float)SIMP_G2;
    float y2 = y0 - 1.0f + 2.0f * (float)SIMP_G2;
    int ii = i & 255, jj = j & 255;
    int gi0 = perm[ii + perm[jj]] % 8;
    int gi1 = perm[ii + i1 + perm[jj + j1]] % 8;
    int gi2 = perm[ii + 1 + perm[jj + 1]] % 8;
    float n0, n1, n2;
    float t0 = 0.5f - x0*x0 - y0*y0;
    n0 = t0 < 0 ? 0 : t0*t0*t0*t0 * (GRAD2[gi0][0]*x0 + GRAD2[gi0][1]*y0);
    float t1 = 0.5f - x1*x1 - y1*y1;
    n1 = t1 < 0 ? 0 : t1*t1*t1*t1 * (GRAD2[gi1][0]*x1 + GRAD2[gi1][1]*y1);
    float t2 = 0.5f - x2*x2 - y2*y2;
    n2 = t2 < 0 ? 0 : t2*t2*t2*t2 * (GRAD2[gi2][0]*x2 + GRAD2[gi2][1]*y2);
    return 70.0f * (n0 + n1 + n2);
}

static void build_perm(int seed, int *perm)
{
    int base[256];
    for (int i = 0; i < 256; i++) base[i] = i;
    unsigned int state = (unsigned int)seed;
    for (int i = 255; i > 0; i--) {
        state = state * 1103515245 + 12345;
        int j = (int)(state % ((unsigned int)(i + 1)));
        int tmp = base[i]; base[i] = base[j]; base[j] = tmp;
    }
    for (int i = 0; i < 256; i++) {
        perm[i] = base[i];
        perm[i + 256] = base[i];
    }
}

static void sample_simplex_grid(int chunk_x, int chunk_z, float freq,
                                const int *perm, float *out)
{
    for (int c = 0; c < 256; c++) {
        float wx = (float)(chunk_x * 16 + (c & 15)) / 4.0f * freq;
        float wz = (float)(chunk_z * 16 + (c >> 4)) / 4.0f * freq;
        out[c] = simplex_noise2d(wx, wz, perm);
    }
}

static int nukkit_pick_biome(int wx, int wz, int seed)
{
    long long hash = (long long)wx * 2345803LL ^ (long long)wz * 9236449LL ^ (long long)seed;
    hash *= hash + 223;
    int temp = (int)((unsigned int)(hash ^ (hash >> 17)) & 0xFFFF);
    int humidity = (int)((unsigned int)(hash * 31 ^ (hash >> 11)) & 0xFFFF);
    int cold = temp < 24576;
    int hot = temp > 40960;
    int wet = humidity > 36000;
    int dry = humidity < 26214;
    if (cold) return wet ? 5 : 12;
    if (hot && dry) return 2;
    if (wet) return 4;
    return 1;
}

/* ------------------------------------------------------------------ */
/* Java world import: byte-identical to JavaBlockTranslator.            */
/*                                                                      */
/* Keep the tables in sync with JavaBlockTranslator's PHP constants     */
/* (JAVA_ONLY_REMAP, NUMERIC_META_FIX, PE_VALID_RANGES) when either     */
/* side changes; native/verify.php asserts byte-identical output.       */
/* ------------------------------------------------------------------ */

/* JAVA_ONLY_REMAP: id -> [newId, newMeta]; -1 = no remap. Mirrors
 * JavaBlockTranslator::JAVA_ONLY_REMAP exactly. */
static const int8_t java_remap_id[256] = {
    -1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,
    -1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,
    -1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,
    -1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,
    -1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,49,
    -1,-1,49,-1,-1,-1,-1,-1,-1,-1,49,-1,-1,-1,-1,-1,-1,1,89,-1,-1,-1,-1,-1,
    -1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,102,-1,-1,-1,-1,-1,0,-1,
    98,89,-1,-1,-1,-1,-1,-1,0,0,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,
    -1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,
    -1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,
    -1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1
};
static const int8_t java_remap_meta[256] = {
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0
};

/* NUMERIC_META_FIX: kind 1 = mask meta, kind 2 = log axis (12 -> vertical). */
static const int8_t java_fix_kind[256] = {
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,2,1,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1,2,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0
};
static const int8_t java_fix_mask[256] = {
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,3,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,
    0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0
};

/* PE-valid ranges (0.15 client renderable ids) — JavaBlockTranslator::
 * PE_VALID_RANGES. */
static const struct { int lo, hi; } java_valid_ranges[] = {
    {0,35},{37,83},{85,118},{120,121},{123,129},{131,136},{139,159},
    {161,165},{167,167},{170,175},{178,187},{193,199},{243,251},{255,255}
};

static int java_is_valid_id(int id)
{
    for (unsigned i = 0; i < sizeof(java_valid_ranges)/sizeof(java_valid_ranges[0]); i++) {
        if (id >= java_valid_ranges[i].lo && id <= java_valid_ranges[i].hi) {
            return 1;
        }
    }
    return 0;
}

/*
 * Sanitize one 16x16x16 section in place (JavaBlockTranslator::
 * sanitizeSection): every emitted state is renderable by the 0.15 client.
 * blocks[4096], data[4096] byte-per-block id/meta. Returns the number of
 * bytes changed (0 means the section was already clean).
 */
int kh_java_sanitize(unsigned char *blocks, unsigned char *data)
{
    int changed = 0;
    for (int i = 0; i < 4096; i++) {
        int id = blocks[i];
        if (java_remap_id[id] >= 0) {
            int nid = java_remap_id[id], nmeta = java_remap_meta[id];
            if (id != nid || data[i] != nmeta) {
                blocks[i] = (unsigned char)nid;
                data[i] = (unsigned char)nmeta;
                changed++;
            }
        } else if (java_fix_kind[id] == 1) {
            int m = data[i] & java_fix_mask[id];
            if (m != data[i]) {
                data[i] = (unsigned char)m;
                changed++;
            }
        } else if (java_fix_kind[id] == 2) {
            if ((data[i] & 0x0C) == 0x0C) {
                data[i] = (unsigned char)(data[i] & 0x03);
                changed++;
            }
        } else if (!java_is_valid_id(id)) {
            if (id != 1 || data[i] != 0) {
                blocks[i] = 1;
                data[i] = 0;
                changed++;
            }
        }
    }
    return changed;
}

/*
 * Decode a packed palette section's 4096 block indices (the bit-unpack half
 * of JavaBlockTranslator::decodePaletteSection — name -> state resolution
 * stays in PHP). Byte-identical to the PHP peekBits loop.
 *
 *   longs[nlongs]    packed 64-bit words; bit 0 of each word is the LSB of
 *                    the first stored index (PHP int semantics: negative
 *                    values arrive as their two's-complement bit pattern)
 *   bits             index width (>= 4)
 *   continuous       1 = pre-1.16 continuous bit stream; 0 = 1.16+ per-word
 *                    layout (floor(64/bits) values per word from bit 0)
 *   pal_id/pal_meta  per-palette-entry resolved (id, meta)
 *   npal             palette size (>= 1)
 *   blocks[4096], data[4096]  outputs (caller pre-zeroed)
 */
void kh_java_palette_fill(const int64_t *longs, int nlongs, int bits,
                          int continuous,
                          const unsigned char *pal_id,
                          const unsigned char *pal_meta, int npal,
                          unsigned char *blocks, unsigned char *data)
{
    if (npal == 1) {
        for (int i = 0; i < 4096; i++) {
            blocks[i] = pal_id[0];
            data[i] = pal_meta[0];
        }
        return;
    }
    if (continuous) {
        /* Pre-1.16: one uninterrupted little-endian bit stream across the
         * whole word array (values may straddle word boundaries). */
        long long total_bits = (long long)nlongs * 64;
        for (int k = 0; k < 4096; k++) {
            long long bit_index = (long long)k * bits;
            if (bit_index + bits > total_bits) {
                break; /* truncated buffer: leave the rest as air */
            }
            int idx = 0;
            int width = bits;
            int got = 0;
            while (width > 0) {
                int word = (int)(bit_index >> 6);
                if (word >= nlongs) {
                    idx = 0;
                    break;
                }
                uint64_t w = (uint64_t)longs[word];
                int off = (int)(bit_index & 63);
                int take = width < 64 - off ? width : 64 - off;
                uint64_t chunk = (w >> off) & (((uint64_t)1 << take) - 1);
                idx |= (int)(chunk << got);
                got += take;
                bit_index += take;
                width -= take;
            }
            if (idx >= npal) {
                idx = 0;
            }
            blocks[k] = pal_id[idx];
            data[k] = pal_meta[idx];
        }
        return;
    }
    /* 1.16+: values never straddle a word. Each word holds
     * floor(64 / bits) values packed from bit 0; the trailing
     * 64 % bits bits of each word are unused. */
    int per_word = 64 / bits;
    for (int k = 0; k < 4096; k++) {
        int word = k / per_word;
        if (word >= nlongs) {
            break; /* truncated buffer: leave the rest as air */
        }
        int off = (k % per_word) * bits;
        uint64_t w = (uint64_t)longs[word];
        int idx = (int)((w >> off) & (((uint64_t)1 << bits) - 1));
        if (idx >= npal) {
            idx = 0;
        }
        blocks[k] = pal_id[idx];
        data[k] = pal_meta[idx];
    }
}

void kh_nukkit_profiles(int chunk_x, int chunk_z, int seed,
                        int *out_heights, int *out_biomes)
{
    int permSF[512], permL[512], permM[512], permB[512], permR[512];
    int rng = (chunk_x * 374761393) ^ (chunk_z * 668265263) ^ (seed * 1103515245);
    rng &= 0x7FFFFFFF;
    build_perm(rng, permSF);
    build_perm(rng, permL);
    build_perm(rng, permM);
    build_perm(rng, permB);
    build_perm(rng, permR);

    float sf[256], land[256], mtn[256], base_n[256], riv[256];
    sample_simplex_grid(chunk_x, chunk_z, 0.125f, permSF, sf);
    sample_simplex_grid(chunk_x, chunk_z, 0.25f, permL, land);
    sample_simplex_grid(chunk_x, chunk_z, 4.0f, permM, mtn);
    sample_simplex_grid(chunk_x, chunk_z, 1.0f, permB, base_n);
    sample_simplex_grid(chunk_x, chunk_z, 2.0f, permR, riv);

    for (int c = 0; c < 256; c++) {
        int wx = chunk_x * 16 + (c & 15);
        int wz = chunk_z * 16 + (c >> 4);
        int canBaseGround = 0, canRiver = 1;

        float lhn = land[c] + 1.0f;
        lhn *= 2.956f;
        lhn = lhn * lhn - 0.6f;
        if (lhn < 0) lhn = 0;

        float mhg = mtn[c] - 0.2f;
        if (mhg < 0) mhg = 0;
        int mountainGen = (int)(13.0f * mhg);

        int landGen = (int)(18.0f * lhn);
        if (landGen > 18) { canBaseGround = 1; landGen = 18; }

        int h = 48 + landGen + mountainGen;
        int biome = 1;

        if (h < 60) {
            if (h < 55) h += (int)(5.0f * sf[c]);
            biome = 0;
            if (h < 43) h = 48;
            canRiver = 0;
        } else if (h >= 60 && h <= 64) {
            biome = 16;
        } else {
            biome = nukkit_pick_biome(wx, wz, seed);
            if (canBaseGround) {
                int bg1 = (int)(18.0f * lhn) - 18;
                int bg2 = (int)(3.0f * (base_n[c] + 1.0f));
                if (bg2 > bg1) bg2 = bg1;
                if (bg2 > mountainGen) bg2 -= mountainGen; else bg2 = 0;
                h += bg2;
            }
        }

        if (canRiver && h <= 57) canRiver = 0;

        if (canRiver) {
            float rv = riv[c];
            if (rv > -0.25f && rv < 0.25f) {
                rv = rv > 0 ? rv : -rv;
                rv = 0.25f - rv;
                rv = rv * rv * 4.0f - 0.0000001f;
                if (rv < 0) rv = 0;
                h -= (int)(rv * 64);
                if (h < 62) {
                    biome = 7;
                    if (h <= 54) {
                        int g1 = 53 + (int)(3.0f * (base_n[c] + 1.0f));
                        int g2 = h < 55 ? 55 : h;
                        h = g1 > g2 ? g1 : g2;
                    }
                }
            }
        }

        out_heights[c] = h;
        out_biomes[c] = biome;
    }
}

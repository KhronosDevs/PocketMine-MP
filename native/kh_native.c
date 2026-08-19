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

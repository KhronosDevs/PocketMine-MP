/*
 * kh_bench.c — FFI microbenchmark prototypes for the hotspots exploration
 * (branch explore/ffi-hotspots-2). NOT part of the shipped native library;
 * built by bench/build_bench.sh into bench/lib/kh_bench.so and loaded only
 * by bench/02_ffi_candidates.php.
 *
 * The functions mirror the integration pattern of the real library
 * (native/kh_native.c): pure C, byte-identical to the PHP counterpart.
 */
#include <stdlib.h>
#include <string.h>
#include <stdint.h>

/* Byte-identical to RegionStorageAdapter::unpackNibbles (2048 -> 4096). */
int kh_unpack_nibbles(const unsigned char *in, size_t len, unsigned char *out)
{
    size_t i;
    for (i = 0; i < len; i++) {
        out[i * 2] = in[i] & 0x0F;
        out[i * 2 + 1] = (in[i] >> 4) & 0x0F;
    }
    return (int)(len * 2);
}

/* Byte-identical to pack('e*') on little-endian x86: n IEEE754 doubles -> 8n bytes.
 * Float64: the ECS transport must round-trip PHP doubles exactly; float32 loses
 * precision on real positions/velocities. */
int kh_pack_doubles(const double *in, size_t n, unsigned char *out)
{
    size_t i;
    for (i = 0; i < n; i++) {
        memcpy(out + i * 8, &in[i], 8);
    }
    return (int)(n * 8);
}

/* Byte-identical to unpack('e*'): 8n bytes -> n doubles. */
int kh_unpack_doubles(const unsigned char *in, size_t n, double *out)
{
    size_t i;
    for (i = 0; i < n; i++) {
        memcpy(&out[i], in + i * 8, 8);
    }
    return (int)n;
}

/* Single smooth-noise evaluation, byte-identical to
 * ParallelGeneratorAdapter::smoothNoise (for the per-call overhead probe). */
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
    int gx = x >> shift, gz = z >> shift;
    int fx = x & (cell - 1), fz = z & (cell - 1);
    int v00 = noise2d(gx, gz, seed);
    int v10 = noise2d(gx + 1, gz, seed);
    int v01 = noise2d(gx, gz + 1, seed);
    int v11 = noise2d(gx + 1, gz + 1, seed);
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

/* The nether column-height probe: 2 octaves x 256 columns (same op shape as
 * kh_noise_octaves, but with the nether generator's shifts/xors). */
void kh_nether_heights(int chunk_x, int chunk_z, int seed, int *out)
{
    const int shifts[2] = { 6, 4 };
    const unsigned int xors[2] = { 0x6E5C2F, 0x3D1B7A };
    for (int c = 0; c < 256; c++) {
        int wx = chunk_x * 16 + (c & 15);
        int wz = chunk_z * 16 + (c >> 4);
        for (int i = 0; i < 2; i++) {
            out[c * 2 + i] = smooth_noise(wx, wz, (int)(seed ^ xors[i]), shifts[i]);
        }
    }
}

/* Single smooth-noise evaluation (per-column boundary-cost probe). */
int kh_smooth_noise_single(int x, int z, int seed, int shift)
{
    return smooth_noise(x, z, seed, shift);
}
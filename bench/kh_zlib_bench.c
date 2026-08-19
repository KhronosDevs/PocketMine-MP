/*
 * kh_zlib_bench.c — FFI wrapper around zlib-ng for compression/decompression
 * benchmarking (branch explore/ffi-hotspots-2).
 *
 * Simplified API: caller allocates output buffer, function returns actual
 * output length (or -1 on error). Avoids pointer-to-scalar FFI issues.
 */

#include <stdlib.h>
#include <string.h>
#include <stdint.h>
#include <zlib-ng.h>

/*
 * One-shot deflate (zlib format, level 7).
 * Caller must provide output buffer of at least deflate_buf_size(in_len) bytes.
 * Returns actual compressed size on success, -1 on error.
 */
int kh_zng_deflate(const unsigned char *in, int in_len,
                   unsigned char *out, int out_capacity)
{
    zng_stream strm;
    memset(&strm, 0, sizeof(strm));

    int rc = zng_deflateInit2(&strm, 7, 8 /* Z_DEFLATED */, 15, 8, 0);
    if (rc != Z_OK) return -1;

    strm.next_in   = in;
    strm.avail_in  = (uint32_t)in_len;
    strm.next_out  = out;
    strm.avail_out = (uint32_t)out_capacity;

    rc = zng_deflate(&strm, Z_FINISH);
    zng_deflateEnd(&strm);

    return (rc == Z_STREAM_END) ? (int)strm.total_out : -1;
}

/*
 * One-shot inflate (zlib format).
 * Caller must provide output buffer (typically >= in_len * 4 for safety).
 * Returns actual decompressed size on success, -1 on error.
 */
int kh_zng_inflate(const unsigned char *in, int in_len,
                   unsigned char *out, int out_capacity)
{
    zng_stream strm;
    memset(&strm, 0, sizeof(strm));

    int rc = zng_inflateInit2(&strm, 15);
    if (rc != Z_OK) return -1;

    strm.next_in   = in;
    strm.avail_in  = (uint32_t)in_len;
    strm.next_out  = out;
    strm.avail_out = (uint32_t)out_capacity;

    rc = zng_inflate(&strm, Z_FINISH);
    zng_inflateEnd(&strm);

    return (rc == Z_STREAM_END) ? (int)strm.total_out : -1;
}

/*
 * Helper: max compressed size for deflate (generous upper bound).
 */
int kh_zng_deflate_bound(int in_len) {
    return in_len + (in_len >> 10) + 64;
}

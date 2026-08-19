#!/usr/bin/env bash
# Builds bench/lib/kh_zlib_bench.so from bench/kh_zlib_bench.c
# (FFI zlib-ng benchmark prototypes — not part of the shipped native library).
set -euo pipefail
cd "$(dirname "$0")"
mkdir -p lib

# Link against zlib-ng (zng_-prefixed symbols). Use pkg-config for flags.
ZLIBNG_CFLAGS=$(pkg-config --cflags zlib-ng 2>/dev/null || echo "")
ZLIBNG_LIBS=$(pkg-config --libs zlib-ng 2>/dev/null || echo "-lz-ng")

gcc -O2 -fPIC -shared -Wall -Wextra \
    $ZLIBNG_CFLAGS \
    -o lib/kh_zlib_bench.so kh_zlib_bench.c \
    $ZLIBNG_LIBS

echo "built bench/lib/kh_zlib_bench.so"

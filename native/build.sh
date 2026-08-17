#!/usr/bin/env bash
# Builds native/lib/kh_native.so from kh_native.c.
#
# The server loads this library through PHP FFI (see NativeAccel.php). It is
# pure C with no external dependencies beyond libc, so the same source builds
# on any platform with a C compiler:
#
#   ./native/build.sh            # linux x86_64 (default, portable -O2)
#   ./native/build.sh --native   # tune for the build machine (-march=native)
#
# A prebuilt linux-x86_64 .so is committed so stock servers never need a
# compiler; rebuild only when kh_native.c changes or for other platforms.
set -euo pipefail

cd "$(dirname "$0")"

SRC=kh_native.c
OUT=lib/kh_native.so
mkdir -p lib

FLAGS="-O2 -fPIC -shared -Wall -Wextra"
if [ "${1:-}" = "--native" ]; then
    FLAGS="$FLAGS -march=native"
fi

gcc $FLAGS -o "$OUT" "$SRC"
echo "built $OUT"

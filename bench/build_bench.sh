#!/usr/bin/env bash
# Builds bench/lib/kh_bench.so from bench/kh_bench.c (FFI microbenchmark
# prototypes only — not part of the shipped native library).
set -euo pipefail
cd "$(dirname "$0")"
mkdir -p lib
gcc -O2 -fPIC -shared -Wall -Wextra -o lib/kh_bench.so kh_bench.c
echo "built bench/lib/kh_bench.so"
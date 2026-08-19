#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * zlib-ng FFI benchmark (branch explore/ffi-hotspots-2).
 *
 * Compares PHP's built-in zlib_encode/zlib_decode (zlib 1.3.2) against FFI
 * calls to zlib-ng 2.3.3 at the real compression level (7) used by
 * NetworkSessionService.
 *
 * Usage: bin/php7/bin/php -d extension=ffi bench/06_zlib_bench.php
 */

require_once __DIR__ . '/../autoload.php';

$libPath = __DIR__ . '/lib/kh_zlib_bench.so';
if (!is_file($libPath)) {
    fwrite(STDERR, "bench/lib/kh_zlib_bench.so not found — run bench/build_zlib_bench.sh first\n");
    exit(1);
}

$zng = FFI::cdef(
    <<<'CDEF'
int kh_zng_deflate(const unsigned char *in, int in_len,
                   unsigned char *out, int out_capacity);
int kh_zng_inflate(const unsigned char *in, int in_len,
                   unsigned char *out, int out_capacity);
int kh_zng_deflate_bound(int in_len);
CDEF,
    $libPath
);

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

function ffiDeflate(FFI $zng, string $data): ?string {
    $len = strlen($data);
    $bound = $zng->kh_zng_deflate_bound($len);
    $in = FFI::new("unsigned char[$len]");
    FFI::memcpy($in, $data, $len);
    $out = FFI::new("unsigned char[$bound]");
    $rc = $zng->kh_zng_deflate($in, $len, $out, $bound);
    if ($rc >= 0) {
        return FFI::string($out, $rc);
    }
    return null;
}

function ffiInflate(FFI $zng, string $compressed, int $maxOut): ?string {
    $len = strlen($compressed);
    $in = FFI::new("unsigned char[$len]");
    FFI::memcpy($in, $compressed, $len);
    $out = FFI::new("unsigned char[$maxOut]");
    $rc = $zng->kh_zng_inflate($in, $len, $out, $maxOut);
    if ($rc >= 0) {
        return FFI::string($out, $rc);
    }
    return null;
}

function measure(callable $fn, int $iters, int $rounds = 7): float {
    for ($i = 0; $i < max(50, min(2000, intdiv($iters, 2))); $i++) {
        $fn();
    }
    $times = [];
    for ($r = 0; $r < $rounds; $r++) {
        $t0 = hrtime(true);
        for ($i = 0; $i < $iters; $i++) {
            $fn();
        }
        $times[] = (hrtime(true) - $t0) / $iters;
    }
    sort($times);
    return $times[intdiv(count($times), 2)] / 1000; // -> us/op
}

/* ------------------------------------------------------------------ */
/* Generate realistic payloads                                         */
/* ------------------------------------------------------------------ */
$payloads = [
    'chunk 81KB'    => random_bytes(81920),
    'batch 4KB'     => random_bytes(4096),
    'batch 16KB'    => random_bytes(16384),
    'packet 128B'   => random_bytes(128),
    'keepalive 32B' => random_bytes(32),
];

/* ------------------------------------------------------------------ */
/* Verify correctness                                                  */
/* ------------------------------------------------------------------ */
echo "=== Correctness verification ===\n";
$allOk = true;
foreach ($payloads as $name => $data) {
    $phpCompressed = zlib_encode($data, ZLIB_ENCODING_DEFLATE, 7);
    $ffiCompressed = ffiDeflate($zng, $data);
    $ffiRoundtrip = ($ffiCompressed !== null) ? ffiInflate($zng, $ffiCompressed, strlen($data) * 4) : null;
    $phpRoundtrip = zlib_decode($ffiCompressed ?? '', 64 * 1024 * 1024);

    $compressedMatch = ($ffiCompressed === $phpCompressed);
    $ffiOk = ($ffiRoundtrip === $data);
    $phpOk = ($phpRoundtrip === $data);
    $status = ($ffiOk && $phpOk) ? 'ok' : 'FAIL';
    if ($status === 'FAIL') $allOk = false;

    printf("  %-18s compressed=%s  ffi_rt=%s  php_rt=%s  [%s]\n",
        $name,
        $compressedMatch ? 'IDENTICAL' : 'DIFFERENT (php=' . strlen($phpCompressed) . ' ffi=' . strlen((string)$ffiCompressed) . ')',
        $ffiOk ? 'ok' : 'FAIL',
        $phpOk ? 'ok' : 'FAIL',
        $status
    );

    if (!$compressedMatch) {
        printf("    php first 16: %s\n", bin2hex(substr($phpCompressed, 0, 16)));
        printf("    ffi first 16: %s\n", bin2hex(substr((string)$ffiCompressed, 0, 16)));
    }
}

if (!$allOk) {
    fwrite(STDERR, "\nRound-trip FAILED — cannot proceed with benchmark.\n");
    exit(1);
}
echo "  All payloads round-trip correctly.\n\n";

/* ------------------------------------------------------------------ */
/* Benchmark: deflate                                                  */
/* ------------------------------------------------------------------ */
echo "=== Benchmark: one-shot deflate (init+compress+end per call) ===\n";
printf("  %-18s  %12s  %12s  %8s  %8s\n", 'payload', 'PHP (us)', 'FFI (us)', 'speedup', 'cut');
echo "  " . str_repeat('-', 70) . "\n";

foreach ($payloads as $name => $data) {
    $phpFn = fn() => zlib_encode($data, ZLIB_ENCODING_DEFLATE, 7);
    $ffiFn = static function () use ($zng, $data): void { ffiDeflate($zng, $data); };

    $phpUs = measure($phpFn, 2000);
    $ffiUs = measure($ffiFn, 2000);

    printf("  %-18s  %10.2f us  %10.2f us  %6.1fx  %6.1f%%\n",
        $name, $phpUs, $ffiUs, $phpUs / $ffiUs, (1 - $ffiUs / $phpUs) * 100);
}

/* ------------------------------------------------------------------ */
/* Benchmark: inflate                                                  */
/* ------------------------------------------------------------------ */
echo "\n=== Benchmark: one-shot inflate (init+decompress+end per call) ===\n";
printf("  %-18s  %12s  %12s  %8s  %8s\n", 'payload', 'PHP (us)', 'FFI (us)', 'speedup', 'cut');
echo "  " . str_repeat('-', 70) . "\n";

foreach ($payloads as $name => $data) {
    $compressed = zlib_encode($data, ZLIB_ENCODING_DEFLATE, 7);
    $maxOut = strlen($data) * 4;

    $phpFn = fn() => zlib_decode($compressed, 64 * 1024 * 1024);
    $ffiFn = static function () use ($zng, $compressed, $maxOut): void { ffiInflate($zng, $compressed, $maxOut); };

    $phpUs = measure($phpFn, 2000);
    $ffiUs = measure($ffiFn, 2000);

    printf("  %-18s  %10.2f us  %10.2f us  %6.1fx  %6.1f%%\n",
        $name, $phpUs, $ffiUs, $phpUs / $ffiUs, (1 - $ffiUs / $phpUs) * 100);
}

echo "\nPHP " . PHP_VERSION . " / " . php_uname('m') . "\n";

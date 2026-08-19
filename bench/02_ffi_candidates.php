#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * FFI hotspot microbenchmarks (branch explore/ffi-hotspots-2).
 *
 * Compares each candidate's current pure-PHP implementation against an FFI
 * prototype reusing the established integration pattern (native/kh_native.c +
 * NativeAccel.php: lazy FFI::cdef, per-thread static buffers, byte-identical
 * output with a pure-PHP fallback). The prototypes live in bench/kh_bench.c
 * (built by bench/build_bench.sh into bench/lib/kh_bench.so) — NOT the shipped
 * native library.
 *
 * Every comparison asserts byte-identity against the PHP implementation on
 * adversarial inputs before timing. Each op is warmed up and measured as the
 * median of 7 rounds of `iters` back-to-back executions (opcache-enabled, same
 * binary the server uses).
 *
 * Candidates:
 *   A  nibble pack   4096 -> 2048   (RegionStorageAdapter::packNibbles; same op
 *                                    as ChunkSerializer::packNibbles, which is
 *                                    already in FFI — the left-out call site)
 *   B  nibble unpack 2048 -> 4096   (RegionStorageAdapter::unpackNibbles; NO FFI
 *                                    equivalent exists yet)
 *   C  ECS parallel snapshot transport (the dominant per-tick cost measured in
 *      bench/01_tick_profile.php: ~26ms of a ~27ms tick): JSON (current) vs
 *      pure-PHP pack/unpack vs FFI float arrays
 *   D  entity metadata encode (Binary::writeMetadata; per-tick broadcast path,
 *      small payload)
 *   E  nether column heights (256 cols x 2 octaves of smoothNoise; the nether
 *      generator bypasses the batched native noise path used by the overworld)
 *
 * Usage: bin/php7/bin/php bench/02_ffi_candidates.php
 */

require_once __DIR__ . '/../autoload.php';

use pocketmine\core\resource\NativeAccel;
use pocketmine\utils\Binary;

if (!NativeAccel::available()) {
    fwrite(STDERR, "NativeAccel unavailable (no kh_native.so / FFI disabled) — cannot benchmark the shipped FFI.\n");
    exit(1);
}

const BENCH_CDEF = <<<'CDEF'
int kh_unpack_nibbles(const unsigned char *in, size_t len, unsigned char *out);
int kh_pack_doubles(const double *in, size_t n, unsigned char *out);
int kh_unpack_doubles(const unsigned char *in, size_t n, double *out);
void kh_nether_heights(int chunk_x, int chunk_z, int seed, int *out);
int kh_smooth_noise_single(int x, int z, int seed, int shift);
CDEF;

$bench = FFI::cdef(BENCH_CDEF, __DIR__ . '/lib/kh_bench.so');

/* ------------------------------------------------------------------ */
/* Measurement helper: warmup + median of rounds of back-to-back runs. */
/* ------------------------------------------------------------------ */
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
        $times[] = (hrtime(true) - $t0) / $iters; // ns / op
    }
    sort($times);
    return $times[intdiv(count($times), 2)] / 1000; // -> us / op
}

function row(string $name, float $phpUs, float $ffiUs): array {
    $speedup = $phpUs / $ffiUs;
    $pct = (1 - $ffiUs / $phpUs) * 100;
    return [$name, $phpUs, $ffiUs, $speedup, $pct];
}

$rows = [];
$discarded = [];

/* ------------------------------------------------------------------ */
/* A. Nibble pack 4096 -> 2048                                          */
/* ------------------------------------------------------------------ */
$src = '';
mt_srand(1);
for ($i = 0; $i < 4096; $i++) {
    $src .= chr(mt_rand(0, 255));
}

function phpPackNibbles(string $data): string {
    $out = str_repeat("\x00", intdiv(strlen($data), 2) + (strlen($data) % 2));
    $len = strlen($data);
    for ($i = 0; $i + 1 < $len; $i += 2) {
        $out[$i >> 1] = chr((ord($data[$i]) & 0x0F) | ((ord($data[$i + 1]) & 0x0F) << 4));
    }
    if ($len % 2 === 1) {
        $out[$len >> 1] = chr(ord($data[$len - 1]) & 0x0F);
    }
    return $out;
}

$phpA = phpPackNibbles($src);
$ffiA = NativeAccel::packNibbles($src);
if ($phpA !== $ffiA) {
    throw new RuntimeException('A: FFI packNibbles is not byte-identical');
}
$rows['A_pack_nibbles'] = row(
    'nibble pack 4096->2048',
    measure(fn() => phpPackNibbles($src), 2000),
    measure(fn() => NativeAccel::packNibbles($src), 2000)
);

/* ------------------------------------------------------------------ */
/* B. Nibble unpack 2048 -> 4096 (no FFI equivalent shipped yet)        */
/* ------------------------------------------------------------------ */
function phpUnpackNibbles(string $nibbles): string {
    $out = str_repeat("\x00", strlen($nibbles) * 2);
    for ($i = 0, $len = strlen($nibbles); $i < $len; $i++) {
        $byte = ord($nibbles[$i]);
        $out[$i * 2] = chr($byte & 0x0F);
        $out[$i * 2 + 1] = chr(($byte >> 4) & 0x0F);
    }
    return $out;
}

$nib = substr($src, 0, 2048);
$phpB = phpUnpackNibbles($nib);

static $unInBuf = null, $unOutBuf = null;
$unpackFfi = static function (string $in) use ($bench, &$unInBuf, &$unOutBuf): string {
    $len = strlen($in);
    if ($unInBuf === null) {
        $unInBuf = FFI::new('unsigned char[2048]');
        $unOutBuf = FFI::new('unsigned char[4096]');
    }
    FFI::memcpy($unInBuf, $in, $len);
    $bench->kh_unpack_nibbles($unInBuf, $len, $unOutBuf);
    return FFI::string($unOutBuf, $len * 2);
};
$ffiB = $unpackFfi($nib);
if ($phpB !== $ffiB) {
    throw new RuntimeException('B: FFI unpackNibbles is not byte-identical');
}
$rows['B_unpack_nibbles'] = row(
    'nibble unpack 2048->4096',
    measure(fn() => phpUnpackNibbles($nib), 2000),
    measure(fn() => $unpackFfi($nib), 2000)
);

/* ------------------------------------------------------------------ */
/* C. ECS snapshot transport (500 entities, 6 float arrays)             */
/* ------------------------------------------------------------------ */
$n = 500;
$payload = [];
foreach (['positionsX', 'positionsY', 'positionsZ', 'velocitiesX', 'velocitiesY', 'velocitiesZ'] as $k) {
    $arr = [];
    for ($i = 0; $i < $n; $i++) {
        $arr[] = mt_rand(-100000, 100000) / 100 + 0.25; // +0.25 forces a float (PHP `/` returns int on even division)
    }
    $payload[$k] = $arr;
}

$jsonRoundtrip = static function () use ($payload): void {
    $s = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    json_decode($s, true, 512, JSON_THROW_ON_ERROR);
};

$packRoundtrip = static function () use ($payload, $n): void {
    $out = '';
    foreach ($payload as $arr) {
        $out .= pack('e*', ...$arr);
    }
    $off = 0;
    foreach (['positionsX', 'positionsY', 'positionsZ', 'velocitiesX', 'velocitiesY', 'velocitiesZ'] as $k) {
        unpack('e*', substr($out, $off, $n * 8));
        $off += $n * 8;
    }
};

$ffiFloatRoundtrip = static function () use ($bench, $payload, $n): void {
    $buf = FFI::new('double[' . $n . ']');
    $raw = FFI::new('unsigned char[' . ($n * 8) . ']');
    $out = '';
    foreach ($payload as $arr) {
        $j = 0;
        foreach ($arr as $v) {
            $buf[$j++] = $v;
        }
        $bench->kh_pack_doubles($buf, $n, $raw);
        $out .= FFI::string($raw, $n * 8);
    }
    $off = 0;
    foreach (['positionsX', 'positionsY', 'positionsZ', 'velocitiesX', 'velocitiesY', 'velocitiesZ'] as $k) {
        $bytes = substr($out, $off, $n * 8);
        FFI::memcpy($buf, $bytes, $n * 8);
        for ($j = 0; $j < $n; $j++) {
            $_ = $buf[$j];
        }
        $off += $n * 8;
    }
};

// Correctness: the binary transport must round-trip the float payload EXACTLY
// (IEEE754 lossless, float64). JSON also decodes back to the same doubles.
$packRt = '';
foreach ($payload as $arr) {
    $packRt .= pack('e*', ...$arr);
}
$decoded = [];
$off = 0;
foreach (array_keys($payload) as $k) {
    $decoded[$k] = array_values(unpack('e*', substr($packRt, $off, $n * 8)));
    $off += $n * 8;
}
foreach (array_keys($payload) as $k) {
    for ($i = 0; $i < $n; $i++) {
        if ($decoded[$k][$i] !== $payload[$k][$i]) {
            throw new RuntimeException('C: pack round-trip lost float precision');
        }
    }
}

$jsonUs = measure($jsonRoundtrip, 500);
$packUs = measure($packRoundtrip, 2000);
$rows['C_snapshot_rt'] = [
    'snapshot RT 500x6 floats',
    round($jsonUs, 2),
    round($packUs, 2),
    round($jsonUs / $packUs, 1),
    round((1 - $packUs / $jsonUs) * 100, 1),
];
// FFI float transport vs pack (the "can FFI beat pack" probe)
$ffiUs = measure($ffiFloatRoundtrip, 2000);
$rows['C_pack_vs_ffi'] = [
    'pack RT vs FFI float RT',
    round($packUs, 2),
    round($ffiUs, 2),
    round($packUs / $ffiUs, 1),
    round((1 - $ffiUs / $packUs) * 100, 1),
];

/* ------------------------------------------------------------------ */
/* D. Entity metadata encode (small payload, per-tick broadcast)        */
/* ------------------------------------------------------------------ */
$meta = [
    0 => [Binary::DATA_TYPE_BYTE, 0],         // flags
    1 => [Binary::DATA_TYPE_SHORT, 300],      // air
    2 => [Binary::DATA_TYPE_INT, 20],         // health
    4 => [Binary::DATA_TYPE_FLOAT, 0.0],      // motion
    15 => [Binary::DATA_TYPE_STRING, 'zombie'], // name tag
    16 => [Binary::DATA_TYPE_STRING, ''],
];
$metaStr = Binary::writeMetadata($meta);
$metaUs = measure(fn() => Binary::writeMetadata($meta), 20000);
$rows['D_metadata'] = ['metadata encode ' . strlen($metaStr) . 'B', round($metaUs, 3), 0.0, 0.0, 0.0];
$discarded[] = 'D metadata encode: ' . round($metaUs, 3) . ' us/op — payload ~' . strlen($metaStr)
    . ' bytes; a single FFI call + marshalling would exceed the whole op. Not a candidate.';

/* ------------------------------------------------------------------ */
/* E. Nether column heights (256 cols x 2 octaves) — left-out noise     */
/* ------------------------------------------------------------------ */
function phpNoise2D(int $x, int $z, int $seed): int {
    $seed &= 0x7FFFFFFF;
    $n = ($x * 374761393) ^ ($z * 668265263) ^ ($seed * 1103515245);
    $n &= 0x7FFFFFFF;
    $n = ($n ^ ($n >> 13)) * 1274126177;
    $n &= 0x7FFFFFFF;
    $n ^= $n >> 16;
    return $n & 0xFFFF;
}

function phpSmoothNoise(int $x, int $z, int $seed, int $shift): int {
    $cell = 1 << $shift;
    $gx = $x >> $shift;
    $gz = $z >> $shift;
    $fx = $x & ($cell - 1);
    $fz = $z & ($cell - 1);
    $v00 = phpNoise2D($gx, $gz, $seed);
    $v10 = phpNoise2D($gx + 1, $gz, $seed);
    $v01 = phpNoise2D($gx, $gz + 1, $seed);
    $v11 = phpNoise2D($gx + 1, $gz + 1, $seed);
    $u = intdiv($fx * 65536, $cell);
    $u2 = intdiv($u * $u, 65536);
    $u3 = intdiv($u2 * $u, 65536);
    $tx = 3 * $u2 - 2 * $u3;
    $u = intdiv($fz * 65536, $cell);
    $u2 = intdiv($u * $u, 65536);
    $u3 = intdiv($u2 * $u, 65536);
    $tz = 3 * $u2 - 2 * $u3;
    $top = $v00 + intdiv(($v10 - $v00) * $tx, 65536);
    $bottom = $v01 + intdiv(($v11 - $v01) * $tx, 65536);
    return $top + intdiv(($bottom - $top) * $tz, 65536);
}

$cx = 1234;
$cz = -567;
$seed = 42;
$phpNether = [];
for ($c = 0; $c < 256; $c++) {
    $wx = $cx * 16 + ($c & 15);
    $wz = $cz * 16 + ($c >> 4);
    $phpNether[] = phpSmoothNoise($wx, $wz, $seed ^ 0x6E5C2F, 6);
    $phpNether[] = phpSmoothNoise($wx, $wz, $seed ^ 0x3D1B7A, 4);
}

static $nhBuf = null;
$netherFfi = static function () use ($bench, $cx, $cz, $seed, &$nhBuf): void {
    if ($nhBuf === null) {
        $nhBuf = FFI::new('int[512]');
    }
    $bench->kh_nether_heights($cx, $cz, $seed, $nhBuf);
};
$netherFfi();
$ffiNether = array_values(unpack('l*', FFI::string($nhBuf, 512 * 4)));
if ($phpNether !== $ffiNether) {
    throw new RuntimeException('E: FFI nether heights not byte-identical');
}
$netherPhp = static function () use ($cx, $cz, $seed): void {
    for ($c = 0; $c < 256; $c++) {
        $wx = $cx * 16 + ($c & 15);
        $wz = $cz * 16 + ($c >> 4);
        phpSmoothNoise($wx, $wz, $seed ^ 0x6E5C2F, 6);
        phpSmoothNoise($wx, $wz, $seed ^ 0x3D1B7A, 4);
    }
};
$netherPhp();
$rows['E_nether_heights'] = row(
    'nether 256x2 noise (batched FFI)',
    measure($netherPhp, 2000),
    measure($netherFfi, 2000)
);

// Per-column boundary probe: single-call FFI vs PHP smoothNoise.
$singlePhp = fn() => phpSmoothNoise(12345, 6789, $seed ^ 0x6E5C2F, 6);
$singleFfi = static fn() => $bench->kh_smooth_noise_single(12345, 6789, $seed ^ 0x6E5C2F, 6);
if ($singlePhp() !== $singleFfi()) {
    throw new RuntimeException('E2: single noise not byte-identical');
}
$rows['E2_noise_single'] = row(
    'single smoothNoise call',
    measure($singlePhp, 20000),
    measure($singleFfi, 20000)
);

/* ------------------------------------------------------------------ */
/* Output                                                               */
/* ------------------------------------------------------------------ */
$widths = [30, 14, 14, 12, 14];
$fmt = function (array $r) use ($widths): string {
    $cells = [
        $r[0],
        number_format($r[1], 2) . ' us',
        $r[2] > 0 ? number_format($r[2], 2) . ' us' : '-',
        $r[3] > 0 ? number_format($r[3], 1) . 'x' : '-',
        $r[4] != 0 ? number_format($r[4], 1) . '%' : '-',
    ];
    $line = '';
    foreach ($cells as $i => $c) {
        $line .= str_pad($c, $widths[$i]) . ' | ';
    }
    return rtrim($line, ' | ');
};

echo 'FFI candidate microbenchmarks (median of 7 rounds, warmed up, opcache on)' . PHP_EOL;
echo 'PHP ' . PHP_VERSION . ' / ' . php_uname('m') . PHP_EOL;
echo str_repeat('-', 96) . PHP_EOL;
printf('%s | %s | %s | %s | %s', str_pad('candidate', $widths[0]), str_pad('PHP', $widths[1]), str_pad('FFI', $widths[2]), str_pad('speedup', $widths[3]), str_pad('improvement', $widths[4]));
echo PHP_EOL;
echo str_repeat('-', 96) . PHP_EOL;
foreach ($rows as $r) {
    echo $fmt($r) . PHP_EOL;
}
echo str_repeat('-', 96) . PHP_EOL;
echo 'Discarded / notes:' . PHP_EOL;
foreach ($discarded as $d) {
    echo '  - ' . $d . PHP_EOL;
}
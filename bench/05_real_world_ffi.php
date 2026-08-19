#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Real-world measurements of the newly shipped FFI paths (branch
 * explore/ffi-hotspots-2): RegionStorageAdapter pack/unpack nibbles and the
 * nether column heights batch. Each op is measured with the native library
 * ENABLED and DISABLED (pure-PHP fallback) in the same process, so the
 * before/after is apples-to-apples.
 *
 * Usage: bin/php7/bin/php bench/05_real_world_ffi.php
 */

require_once __DIR__ . '/../autoload.php';

use pocketmine\core\resource\NativeAccel;
use pocketmine\adapter\driven\worldgen\ParallelGeneratorAdapter;

putenv('KHRONOS_FAST_TICKS=1');
$kernel = \pocketmine\bootstrap();
$kernel->setAutoShutdownOnRun(false);
$kernel->run(1);

if (!NativeAccel::available()) {
    fwrite(STDERR, "NativeAccel unavailable — cannot benchmark the shipped FFI.\n");
    exit(1);
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
    return $times[intdiv(count($times), 2)] / 1000;
}

$ref = new ReflectionClass(\pocketmine\adapter\driven\storage\RegionStorageAdapter::class);
$pack = $ref->getMethod('packNibbles');
$pack->setAccessible(true);
$unpack = $ref->getMethod('unpackNibbles');
$unpack->setAccessible(true);

$refGen = new ReflectionClass(ParallelGeneratorAdapter::class);
$netherHeights = $refGen->getMethod('netherHeights');
$netherHeights->setAccessible(true);
$genNether = $refGen->getMethod('generateNetherChunk');
$genNether->setAccessible(true);

$full = random_bytes(4096);
$nibbles = random_bytes(2048);

$rows = [];
$run = function (string $name, callable $fn, int $iters) use (&$rows): void {
    NativeAccel::setEnabled(true);
    $ffi = measure($fn, $iters);
    NativeAccel::setEnabled(false);
    $php = measure($fn, $iters);
    NativeAccel::setEnabled(true);
    $rows[] = [$name, $php, $ffi, $php / $ffi, (1 - $ffi / $php) * 100];
};

$run('A packNibbles 4096->2048', fn() => $pack->invoke(null, $full), 5000);
$run('B unpackNibbles 2048->4096', fn() => $unpack->invoke(null, $nibbles), 5000);
$run('E netherHeights 256 cols x2 oct', fn() => $netherHeights->invoke(null, 10, -20, 12345), 3000);
$run('E+ nether chunk gen (full 128-high)', fn() => $genNether->invoke(null, 10, -20, 12345), 200);

echo str_pad('op', 34) . str_pad('php', 12) . str_pad('ffi', 12) . str_pad('x', 8) . "cut\n";
foreach ($rows as [$name, $php, $ffi, $speedup, $pct]) {
    printf("%-34s%10.1fus %10.1fus %6.1fx  %.1f%%\n", $name, $php, $ffi, $speedup, $pct);
}

$kernel->shutdown();
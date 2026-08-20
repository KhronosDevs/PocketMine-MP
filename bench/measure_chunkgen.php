#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Phase 9.4 chunk-generation benchmark.
 *
 * Compares three ways to generate N chunks with the terrain generator:
 *   pure    - generateChunkPure() straight on the main thread (no pool)
 *   seq     - one generateChunk() per chunk (each still runs on a pool
 *             worker, but one at a time - serialized)
 *   par     - one generateChunks() call (all N tasks submitted up front,
 *             run concurrently across the pool workers)
 *
 * Correctness is checked by comparing per-chunk heightmap hashes, keeping
 * only compact hashes live so big batches fit inside the default 128M
 * memory limit (a 512-chunk batch materializes ~40MB of decoded chunks).
 *
 * Usage: bin/php7/bin/php measure_chunkgen.php [chunks] [seed]
 *
 * NOTE: the parallel path materializes every decoded chunk in memory at once
 * (~75KB each), so large batches exceed PHP's default 128M limit. For
 * batches >= ~768 chunks use: bin/php7/bin/php -d memory_limit=1G
 * measure_chunkgen.php <chunks> [seed]
 */

require_once __DIR__ . '/../autoload.php';

use pocketmine\adapter\driven\worldgen\ParallelGeneratorAdapter;
use pocketmine\port\driven\GeneratorConfig;

$chunks = (int)($argv[1] ?? 16);
$seed = (int)($argv[2] ?? 12345);

$kernel = \pocketmine\bootstrap();
$port = $kernel->getWorldGenPort();
$config = new GeneratorConfig('normal', $seed, []);

// Spread the requested chunks across the world so no two share a chunk.
$coords = [];
for ($i = 0; $i < $chunks; $i++) {
    $coords[] = [($i * 3) % 200 - 100, intdiv($i, 8) - 8];
}

// 1. Pure main-thread generation (no pool at all).
$t0 = hrtime(true);
$pureHashes = [];
foreach ($coords as [$x, $z]) {
    $pureHashes[] = hash('md5', serialize(ParallelGeneratorAdapter::generateChunkPure($x, $z, 'normal', $seed)->heightmap));
}
$pureMs = (hrtime(true) - $t0) / 1e6;

// Warm up the pool before timing either pool path.
$port->generateChunk(0, 0, $config);

// 2. Sequential: one generateChunk() per chunk (serialized on the pool).
$t0 = hrtime(true);
$seqHashes = [];
foreach ($coords as [$x, $z]) {
    $seqHashes[] = hash('md5', serialize($port->generateChunk($x, $z, $config)->heightmap));
}
$seqMs = (hrtime(true) - $t0) / 1e6;

// 3. Parallel: one generateChunks() call (concurrent across workers).
$t0 = hrtime(true);
$parHashes = [];
foreach ($port->generateChunks($coords, $config) as $chunk) {
    $parHashes[] = hash('md5', serialize($chunk->heightmap));
}
$parMs = (hrtime(true) - $t0) / 1e6;

// All three paths must produce identical terrain.
$identical = $pureHashes === $seqHashes && $pureHashes === $parHashes;

printf(
    "chunks=%d pure=%.2fms seq=%.2fms par=%.2fms speedup(par vs seq)=%.2fx speedup(par vs pure)=%.2fx identical=%s\n",
    $chunks,
    $pureMs,
    $seqMs,
    $parMs,
    $seqMs / max($parMs, 0.001),
    $pureMs / max($parMs, 0.001),
    $identical ? 'yes' : 'no'
);

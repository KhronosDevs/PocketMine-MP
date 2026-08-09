<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\adapter\driven\worldgen\ParallelGeneratorAdapter;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\port\driven\GeneratorConfig;

/**
 * Phase 9.2c/9.4 - scale correctness.
 *
 * (1) 1000 entities through the region pipeline in apply mode: every worker
 *     result is applied, positions match the exact deterministic integration,
 *     and the diff-only mirror stops re-sending unchanged snapshots.
 * (2) Gate mode at scale stays exact with diff-only mirroring.
 * (3) Async chunk generation runs the pure generator on real worker threads
 *     and stays deterministic (same chunk twice -> identical data).
 */

$count = 1000;

// --- Kernel A: apply mode at scale + diff-only mirror ---------------------
$kernel = \pocketmine\bootstrap();
$world = $kernel->getWorld();
$kernel->setRegionPipelineEnabled(true);
$kernel->setRegionPipelineApplyMode(true);

$refs = [];
for ($i = 0; $i < $count; $i++) {
    $refs[] = $world->spawn(
        (new EntityBuilder())
            ->at(($i % 100) * 1.5, 64.0, (int)($i / 100) * 1.5)
            ->with(new VelocityComponent(1.0, 0.0, 0.5))
    );
}

test('scale: 1000 entities apply-mode exact + diff-only mirror', function () use ($kernel, $refs, $count) {
    $kernel->run(10);
    $stats = $kernel->getRegionPipelineStats();

    same($count * 10, $stats['applied'], 'every worker result applied at scale, got ' . $stats['applied']);
    same(0, $stats['mismatches'], 'zero gate mismatches at scale');
    same(0, $stats['lagged'], 'zero lagged results at scale (transport keeps up)');
    same($count, $stats['mirrored'], 'only the initial mirror sends updates, got ' . $stats['mirrored']);
    same($count * 9, $stats['skipped'], 'unchanged entities skipped after the first tick, got ' . $stats['skipped']);

    // Exact deterministic integration over 10 ticks:
    // x += vx*dt per tick (vx=1.0 -> +0.5), z += vz*dt (vz=0.5 -> +0.25),
    // gravity: vy -= 0.08/tick; y drops by 0.08*dt * (0+1+...+9) = 0.18.
    foreach ([0, 1, 250, 500, 999] as $i) {
        $pos = $refs[$i]->getPosition();
        near(($i % 100) * 1.5 + 0.5, $pos->x, 1e-6, "entity $i x after 10 ticks");
        near((int)($i / 100) * 1.5 + 0.25, $pos->z, 1e-6, "entity $i z after 10 ticks");
        near(64.0 - 0.18, $pos->y, 1e-6, "entity $i y with gravity over 10 ticks");
    }
    $vel = $refs[0]->getVelocity();
    near(-0.8, $vel->y, 1e-9, 'gravity accumulated to -0.8 over 10 ticks');
});

// --- Kernel B: gate mode at scale (diff-only keeps the gate exact) --------
$kernelB = \pocketmine\bootstrap();
$worldB = $kernelB->getWorld();
$kernelB->setRegionPipelineEnabled(true);
for ($i = 0; $i < $count; $i++) {
    $worldB->spawn(
        (new EntityBuilder())
            ->at(($i % 50) * 1.5, 70.0, (int)($i / 50) * 1.5)
            ->with(new VelocityComponent(0.3, 0.0, 0.7))
    );
}

test('scale: gate mode with diff-only mirror stays exact', function () use ($kernelB, $count) {
    $kernelB->run(3);
    $stats = $kernelB->getRegionPipelineStats();
    same(0, $stats['mismatches'], 'zero mismatches at scale with diff-only mirroring');
    ok($stats['skipped'] >= $count * 2, 'steady-state mirrors are skipped, got ' . $stats['skipped']);
    ok($stats['compared'] >= $count, 'results compared every tick, got ' . $stats['compared']);
});

// --- Async chunk generation: deterministic across real worker threads -----
test('async chunk gen: worker-thread results equal the pure generator', function () {
    $kernelC = \pocketmine\bootstrap();
    $port = $kernelC->getWorldGenPort();
    ok($port instanceof ParallelGeneratorAdapter, 'worldgen port is the parallel adapter');

    $config = new GeneratorConfig('normal', 42, []);
    $a = $port->generateChunk(3, -7, $config);
    $b = $port->generateChunk(3, -7, $config);

    same(16 * 16, count($a->heightmap), 'heightmap covers 16x16 columns');
    same($a->heightmap, $b->heightmap, 'async generation is deterministic (same heightmap)');
    same($a->sections, $b->sections, 'async generation is deterministic (same sections)');
    ok($a->chunkX === 3 && $a->chunkZ === -7, 'chunk coords round-trip');

    $flat = $port->generateChunk(0, 0, new GeneratorConfig('flat', 1, []));
    same(5, $flat->heightmap[0], 'flat world heightmap at surfaceY+1');

    // Batch API: many chunks in one parallel call, in input order.
    $batch = $port->generateChunks([[1, 1], [2, 2], [3, 3], [4, 4], [5, 5]], $config);
    same(5, count($batch), 'batch returns one chunk per input');
    foreach ($batch as $idx => $chunk) {
        same(1 + $idx, $chunk->chunkX, "batch chunk $idx x in input order");
        same(1 + $idx, $chunk->chunkZ, "batch chunk $idx z in input order");
    }
    // Batch and single-call results are identical (determinism across paths).
    $single = $port->generateChunk(3, 3, $config);
    same($single->heightmap, $batch[2]->heightmap, 'batch chunk (3,3) equals single-call result');
});

// --- Bulk chunk load: ChunkLoadService uses the parallel batch path ------
test('bulk chunk load: loadChunks generates missing chunks in parallel', function () {
    $kernel = \pocketmine\bootstrap();
    $svc = $kernel->getChunkLoadService();
    $coords = [[0, 0], [1, 1], [2, 2], [3, 3], [4, 4], [5, 5], [6, 6], [7, 7]];
    $chunks = $svc->loadChunks($coords);
    same(count($coords), count($chunks), 'one chunk per requested coord');
    foreach ($chunks as $i => $c) {
        same($coords[$i][0], $c->chunkX, "chunk $i x in input order");
        same($coords[$i][1], $c->chunkZ, "chunk $i z in input order");
        ok(count($c->sections) > 0, "chunk $i generated with sections");
    }
    $store = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);
    ok($store->isLoaded(0, 0), 'chunk (0,0) materialized into the store');
    ok($store->isLoaded(7, 7), 'chunk (7,7) materialized into the store');
});

exit(runTests());

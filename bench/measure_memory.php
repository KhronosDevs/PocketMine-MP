#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Phase 11.3 memory profiler.
 *
 * Measures where the server's memory goes and proves the loaded-chunk budget
 * works:
 *   1. Baseline: ECS + kernel footprint before any workload.
 *   2. Entities: spawn in batches, measure per-entity cost and archetype
 *      array sizing (capacity vs valid slots, wasted slots).
 *   3. Archetype churn: spawn -> despawn -> respawn cycles prove freed
 *      indices are REUSED (the index high-water mark must stay flat), not
 *      leaked - regression guard for the Archetype free-list fix.
 *   4. Chunks: load chunks against a small budget and show the store evicts
 *      the oldest residents (count stays at budget, bytes bounded).
 *
 * Usage: bin/php7/bin/php -d memory_limit=1G measure_memory.php [entities] [chunks]
 *   entities   spawn batch size (default: 2000)
 *   chunks     chunks to load against a 64-chunk budget (default: 200)
 */

require_once __DIR__ . '/../autoload.php';

use pocketmine\core\component\VelocityComponent;
use pocketmine\core\ecs\EntityBuilder;

$entities = (int)($argv[1] ?? 2000);
$chunks = (int)($argv[2] ?? 200);

function mb(int|float $bytes): string {
    return sprintf('%.2f MB', $bytes / 1048576);
}

function kb(int|float $bytes): string {
    return sprintf('%.1f KB', $bytes / 1024);
}

$kernel = \pocketmine\bootstrap();
$world = $kernel->getWorld();

// 1. Baseline
gc_collect_cycles();
$baseProfile = $kernel->getMemoryProfile();
printf("== baseline ==\n");
printf("  php current %s peak %s | entities %d | archetypes %d | chunks %d\n",
    mb($baseProfile['phpCurrentBytes']), mb($baseProfile['phpPeakBytes']),
    $baseProfile['entities'], $baseProfile['archetypes'], $baseProfile['loadedChunks']);

// 2. Entities: spawn in batches, sample per-entity cost at the end of each batch.
$t0 = hrtime(true);
$prevBytes = $baseProfile['phpCurrentBytes'];
$prevEntities = 0;
printf("== entities (batch of %d, 4 batches) ==\n", $entities);
for ($batch = 1; $batch <= 4; $batch++) {
    for ($i = 0; $i < $entities; $i++) {
        $world->spawn(
            (new EntityBuilder())
                ->at(($i % 100) * 1.5, 64.0 + ($i % 8), (int)($i / 100) * 1.5)
                ->with(new VelocityComponent(0.5, 0.0, 0.3))
        );
    }
    gc_collect_cycles();
    $p = $kernel->getMemoryProfile();
    $deltaEntities = $p['entities'] - $prevEntities;
    $deltaBytes = $p['phpCurrentBytes'] - $prevBytes;
    $perEntity = $deltaEntities > 0 ? $deltaBytes / $deltaEntities : 0;
    // Sum wasted slots across archetypes (nulls awaiting reuse).
    $wasted = 0;
    $cap = 0;
    foreach ($p['archetypeStats'] as $stats) {
        foreach ($stats['components'] as $c) {
            $wasted += $c['wasted'];
            $cap += $c['capacity'];
        }
    }
    printf("  batch %d: entities %d (+%d) | +%s (%s/entity) | archetype slots %d (wasted %d)\n",
        $batch, $p['entities'], $deltaEntities, kb($deltaBytes), kb($perEntity), $cap, $wasted);
    $prevBytes = $p['phpCurrentBytes'];
    $prevEntities = $p['entities'];
}
$spawnMs = (hrtime(true) - $t0) / 1e6;

// 3. Archetype churn: despawn everything, respawn, and prove the index
//    high-water mark does not grow (freed indices are reused, not leaked).
$before = $kernel->getMemoryProfile();
$highWater = 0;
foreach ($before['archetypeStats'] as $stats) {
    $highWater = max($highWater, $stats['indexHighWater']);
}
foreach ($world->getEntities() as $entity) {
    $world->despawn($entity);
}
$world->tick(0.05); // flush removals
gc_collect_cycles();

for ($i = 0; $i < $entities; $i++) {
    $world->spawn(
        (new EntityBuilder())
            ->at(($i % 100) * 1.5, 64.0 + ($i % 8), (int)($i / 100) * 1.5)
            ->with(new VelocityComponent(0.5, 0.0, 0.3))
    );
}
gc_collect_cycles();
$after = $kernel->getMemoryProfile();
$newHighWater = 0;
foreach ($after['archetypeStats'] as $stats) {
    $newHighWater = max($newHighWater, $stats['indexHighWater']);
}
printf("== archetype churn ==\n");
printf("  index high-water before=%d after=%d (flat = freed indices reused) | entities %d -> %d\n",
    $highWater, $newHighWater, $before['entities'], $after['entities']);
printf("  spawn total %.0f ms\n", $spawnMs);

// 4. Chunks: enforce a 64-chunk budget while loading more than that.
$budget = 64;
$kernel->getChunkLoadService()->setMaxLoadedChunks($budget);
$store = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);

$t0 = hrtime(true);
for ($i = 0; $i < $chunks; $i++) {
    $x = ($i * 7) % 400 - 200;
    $z = intdiv($i, 4) - 25;
    $kernel->getChunkLoadService()->loadChunk($x, $z);
}
$chunkMs = (hrtime(true) - $t0) / 1e6;
gc_collect_cycles();
$p = $kernel->getMemoryProfile();
printf("== chunks (budget %d) ==\n", $budget);
printf("  loaded %d chunks -> resident %d (budget %d) | payload %s | load total %.0f ms\n",
    $chunks, $p['loadedChunks'], $budget, mb($p['chunkBytes']), $chunkMs);

printf("== final ==\n");
printf("  php current %s peak %s\n", mb($p['phpCurrentBytes']), mb($p['phpPeakBytes']));

$kernel->shutdown();

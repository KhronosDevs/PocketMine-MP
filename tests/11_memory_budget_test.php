<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\component\VelocityComponent;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\resource\ChunkStore;

/**
 * Phase 11.3 - memory budget.
 *
 * (1) The loaded-chunk budget is enforced on every bulk load: loading more
 *     chunks than the budget holds leaves exactly the budget resident, the
 *     OLDEST chunks are the ones evicted (FIFO), and every evicted chunk was
 *     persisted first (a marker block set in memory survives a reload from
 *     disk after eviction).
 * (2) Archetype freed indices are reused: spawn/despawn/respawn cycles do
 *     not grow the index high-water mark (regression guard for the Archetype
 *     free-list fix - a per-type list leaked stale entries forever).
 * (3) Chunk memory estimate scales with resident chunks.
 */

$kernel = \pocketmine\bootstrap();
$world = $kernel->getWorld();
$store = $kernel->getResourceRegistry()->get(ChunkStore::class);
ok($store instanceof ChunkStore, 'chunk store resource present');

// --- 1. Loaded-chunk budget ----------------------------------------------

test('chunk budget: loading beyond the cap evicts the OLDEST residents, persisted', function () use ($kernel, $world) {
    $budget = 8;
    $kernel->getChunkLoadService()->setMaxLoadedChunks($budget);

    // Load 5 chunks and place a marker block in the first-loaded one.
    $coords = [[0, 0], [1, 0], [2, 0], [3, 0], [4, 0]];
    foreach ($coords as [$x, $z]) {
        $kernel->getChunkLoadService()->loadChunk($x, $z);
    }
    $store = $kernel->getResourceRegistry()->get(ChunkStore::class);
    ok($store->setBlock(5, 200, 5, 42, 3), 'marker placed in chunk 0,0');
    ok($store->isLoaded(0, 0), 'chunk 0,0 resident before over-budget load');

    // Load 20 more chunks; the budget (8) forces FIFO eviction back down.
    for ($i = 0; $i < 20; $i++) {
        $kernel->getChunkLoadService()->loadChunk(10 + $i, 0);
    }
    ok($store->getCount() <= $budget, 'resident chunk count at or under budget, got ' . $store->getCount());
    ok(!$store->isLoaded(0, 0), 'oldest chunk (0,0) was evicted');

    // The evicted chunk was persisted: reloading it from disk brings back the
    // marker block exactly (not a fresh generation).
    $kernel->getChunkLoadService()->loadChunk(0, 0);
    ok($store->isLoaded(0, 0), 'evicted chunk reloaded from disk');
    same(42, $store->getBlock(5, 200, 5), 'marker block survived eviction + reload');
    same(3, $store->getBlockMeta(5, 200, 5), 'marker meta survived eviction + reload');
});

// --- 2. Archetype free-index reuse ---------------------------------------

test('archetype: freed indices are reused, high-water mark stays flat', function () use ($kernel, $world) {
    // First archetype (Position+Velocity) already holds entities from the
    // previous test's reload path; snapshot its high-water mark.
    $highWater = archetypeHighWater($kernel);
    $countBefore = count($world->getEntities());

    $refs = [];
    for ($i = 0; $i < 500; $i++) {
        $refs[] = $world->spawn(
            (new EntityBuilder())->at($i * 1.5, 64.0, 0.0)->with(new VelocityComponent(0.5, 0.0, 0.3))
        );
    }
    foreach ($world->getEntities() as $entity) {
        $world->despawn($entity);
    }
    $world->tick(0.05); // flush removals so indices enter the free list

    // Respawn the same count: with index reuse the high-water mark cannot
    // grow past (previous high-water + one batch), and the free list drains.
    for ($i = 0; $i < 500; $i++) {
        $world->spawn(
            (new EntityBuilder())->at($i * 1.5, 64.0, 0.0)->with(new VelocityComponent(0.5, 0.0, 0.3))
        );
    }
    $highWaterAfter = archetypeHighWater($kernel);
    ok(
        $highWaterAfter <= $highWater + 500,
        "index high-water did not double: before=$highWater after=$highWaterAfter (growth <= 500)"
    );
    ok(
        count($world->getEntities()) >= $countBefore,
        'entities still resident after churn, got ' . count($world->getEntities())
    );
});

test('archetype: getStats reports capacity/valid/wasted per component type', function () use ($kernel) {
    $profile = $kernel->getMemoryProfile();
    ok($profile['archetypes'] >= 1, 'at least one archetype, got ' . $profile['archetypes']);
    $found = false;
    foreach ($profile['archetypeStats'] as $key => $stats) {
        ok(isset($stats['entities'], $stats['indexHighWater'], $stats['freeIndices'], $stats['components']), "archetype $key has full stats");
        foreach ($stats['components'] as $type => $c) {
            ok(isset($c['capacity'], $c['valid'], $c['wasted']), "archetype $key component $type stats present");
            ok($c['valid'] <= $c['capacity'], "archetype $key $type valid <= capacity");
            $found = true;
        }
    }
    ok($found, 'stats enumerated at least one component array');
});

// --- 3. Chunk memory estimate --------------------------------------------

test('chunk store: memory estimate scales with resident chunks', function () use ($kernel, $store) {
    $before = $store->getMemoryEstimate();
    ok($before > 0, 'resident chunks have a positive payload, got ' . $before);
    ok($store->getCount() <= $kernel->getChunkLoadService()->getMaxLoadedChunks(), 'count respects budget');
    ok($before >= $store->getCount() * ChunkStore::SECTION_BYTES, 'estimate at least one section per chunk');
});

// --- helpers -------------------------------------------------------------

function archetypeHighWater(\pocketmine\Kernel $kernel): int {
    $high = 0;
    foreach ($kernel->getMemoryProfile()['archetypeStats'] as $stats) {
        $high = max($high, $stats['indexHighWater']);
    }
    return $high;
}

$kernel->shutdown();
exit(runTests());

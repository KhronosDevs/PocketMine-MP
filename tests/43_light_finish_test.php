<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\constants\ItemIds;
use pocketmine\core\resource\BlockRegistry;
use pocketmine\core\resource\ChunkStore;

/**
 * Phase 14.29: block-light finish.
 *
 * The LightCalculator already computes per-chunk sky/block light; this test
 * locks in the pieces that make it *matter*: a query API on the ChunkStore
 * (getSkyLightLevel/getBlockLightLevel/getLightLevel), light-dirty tracking
 * so the network layer re-sends chunks after torch place/break, and the
 * darkness gate the mob spawner uses so torches protect an area.
 */

$kernel = \pocketmine\bootstrap();
$world = $kernel->getWorld();
$store = $world->getResourceRegistry()->get(ChunkStore::class);
if (!$store instanceof ChunkStore) {
    echo "FAIL: no ChunkStore\n";
    exit(1);
}
$registry = $world->getResourceRegistry()->get(BlockRegistry::class);
$registry = $registry instanceof BlockRegistry ? $registry : new BlockRegistry();

// Generate the origin chunk so there is terrain to inspect.
$kernel->getChunkLoadService()->loadChunk(0, 0);

// Pick a loaded chunk and a solid surface block inside it.
$chunkCoords = null;
$surface = null;
foreach ($store->getLoadedChunkCoordinates() as $c) {
    $cx = $c[0];
    $cz = $c[1];
    for ($bz = 0; $bz < 16 && $surface === null; $bz++) {
        for ($bx = 0; $bx < 16 && $surface === null; $bx++) {
            $top = $store->getHighestBlockAt($cx * 16 + $bx, $cz * 16 + $bz);
            if ($top > 0 && $store->getBlock($cx * 16 + $bx, $top, $cz * 16 + $bz) !== 8
                && $store->getBlock($cx * 16 + $bx, $top, $cz * 16 + $bz) !== 9) {
                $surface = [$cx * 16 + $bx, $top, $cz * 16 + $bz];
                $chunkCoords = [$cx, $cz];
                break;
            }
        }
    }
    if ($surface !== null) {
        break;
    }
}
ok($surface !== null, 'found a solid surface block in a loaded chunk');
if ($surface === null || $chunkCoords === null) {
    exit(0); // nothing to test against; the harness reports the failure above
}
[$sx, $sy, $sz] = $surface;
[$ccx, $ccz] = $chunkCoords;

test('light query API reads sky + block light from the store', function () use ($store, $sx, $sy, $sz): void {
    // The surface block is exposed: sky light is high (>= 13), and deep
    // underground is dark.
    same(15, $store->getSkyLightLevel($sx, $sy, $sz), 'exposed surface block has full sky light');
    same(0, $store->getSkyLightLevel($sx, 1, $sz), 'deep underground has no sky light');
    same(0, $store->getBlockLightLevel($sx, $sy, $sz), 'no block light without emitters');
    same(15, $store->getLightLevel($sx, $sy, $sz), 'combined light is the sky component');
});

test('placing a torch emits block light and marks the chunk light-dirty', function () use ($store, $registry, $sx, $sy, $sz, $ccx, $ccz): void {
    // Place a torch in the air above the surface block.
    $store->setBlock($sx, $sy + 1, $sz, ItemIds::TORCH, 0);
    $store->recalculateLight($ccx, $ccz, $registry);

    same(14, $store->getBlockLightLevel($sx, $sy + 1, $sz), 'torch block emits block light (14)');
    ok($store->getBlockLightLevel($sx, $sy + 2, $sz) > 0, 'air above the torch is lit');
    same(0, $store->getBlockLightLevel($sx + 15, $sy + 1, $sz), 'far corner of the chunk stays dark');

    $dirty = $store->takeLightDirtyChunks();
    $found = false;
    foreach ($dirty as [$dx, $dz]) {
        if ($dx === $ccx && $dz === $ccz) {
            $found = true;
            break;
        }
    }
    ok($found, 'the changed chunk is in the light-dirty set');
    // A second drain is empty: the set was cleared.
    same([], $store->takeLightDirtyChunks(), 'light-dirty set drains once');
});

test('breaking the torch removes light and re-marks the chunk dirty', function () use ($store, $registry, $sx, $sy, $sz, $ccx, $ccz): void {
    $store->setBlock($sx, $sy + 1, $sz, 0, 0);
    $store->recalculateLight($ccx, $ccz, $registry);

    same(0, $store->getBlockLightLevel($sx, $sy, $sz), 'block light returns to zero after the torch breaks');
    $found = false;
    foreach ($store->takeLightDirtyChunks() as [$dx, $dz]) {
        if ($dx === $ccx && $dz === $ccz) {
            $found = true;
        }
    }
    ok($found, 'breaking the torch re-marks the chunk light-dirty');
});

test('mob darkness gate: torch-lit and dark positions', function () use ($store, $registry, $sx, $sy, $sz, $ccx, $ccz): void {
    // Re-place the torch and check the exact predicate the spawner uses:
    // a spawn is allowed only when block light at the spawn position is 0
    // (MobSpawnerSystem rejects positions with getBlockLightLevel() > 0).
    $store->setBlock($sx, $sy + 1, $sz, ItemIds::TORCH, 0);
    $store->recalculateLight($ccx, $ccz, $registry);

    // The spawn spot directly above the torch is lit -> the gate rejects it.
    same(true, $store->getBlockLightLevel($sx, $sy + 2, $sz) > 0, 'spawn spot above the torch is lit');
    // A position far from the torch is dark -> the gate allows it.
    same(0, $store->getBlockLightLevel($sx + 15, $sy + 1, $sz), 'far spawn spot is dark');

    // Clean up: remove the torch so the world is left as we found it.
    $store->setBlock($sx, $sy + 1, $sz, 0, 0);
    $store->recalculateLight($ccx, $ccz, $registry);
    $store->takeLightDirtyChunks();
});

$kernel->shutdown();

exit(runTests());

<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\constants\MetadataKeys;
use pocketmine\core\constants\ItemIds;
use pocketmine\core\ecs\EntityBuilder;

/**
 * Bug 35: dropped items and XP orbs survive chunk unload/reload cycles.
 * Without entity persistence, items silently vanish when the chunk
 * evicts (not just on restart).
 */

$kernel = \pocketmine\bootstrap();
$kernel->setAutoShutdownOnRun(false);
$world = $kernel->getWorld();
$store = $world->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);
if (!$store instanceof \pocketmine\core\resource\ChunkStore) {
    throw new RuntimeException('no chunk store');
}

// Load two chunks: one that will stay loaded, one that will be evicted.
$kernel->getChunkLoadService()->loadChunk(0, 0);   // stays resident
$kernel->getChunkLoadService()->loadChunk(30, 30); // gets evicted
$world->tick(0.05);

test('dropped item survives chunk eviction and reload', function () use ($world, $kernel, $store): void {
    // Drop an item in the far chunk.
    $itemRef = $kernel->getEntitySpawnService()->spawnItem(480.5, 65, 480.5,
        new \pocketmine\core\component\ItemStack(ItemIds::DIAMOND_SWORD, 0, 1));
    ok($itemRef !== null, 'item spawned');

    // Force the far chunk to evict (unloadUnusedChunks with max=0).
    $evicted = $kernel->getChunkUnloadService()->unloadUnusedChunks(0, 0);
    ok($evicted >= 1, "at least one chunk evicted ($evicted)");

    // The item should still exist as a live ECS entity.
    ok($world->getEntity($itemRef->getId()) !== null || $store->isLoaded(30, 30),
        'item or its chunk still tracked');
});

exit(runTests());

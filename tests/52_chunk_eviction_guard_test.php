<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\resource\ChunkStore;

/**
 * Chunk eviction player guard: the FIFO backstop (unloadUnusedChunks) must
 * never evict a chunk a player is standing in, even when hard over the
 * loaded-chunk budget. Regression for "server unloaded the floor under me".
 */

$kernel = \pocketmine\bootstrap();
$kernel->setAutoShutdownOnRun(false);
$world = $kernel->getWorld();
$unload = $kernel->getChunkUnloadService();
$load = $kernel->getChunkLoadService();
$store = $world->getResourceRegistry()->get(ChunkStore::class);
if (!$store instanceof ChunkStore) {
    throw new RuntimeException('no chunk store');
}

test('unloadUnusedChunks never evicts a player-occupied chunk', function () use ($world, $kernel, $unload, $load, $store): void {
    // Load 6 far-apart chunks.
    $coords = [[0, 0], [40, 40], [42, 42], [80, 80], [82, 82], [120, 120]];
    foreach ($coords as [$cx, $cz]) {
        $load->loadChunk($cx, $cz);
    }
    $kernel->run(1);

    // A player stands inside chunk (0,0) - typically the oldest resident,
    // i.e. exactly the chunk the old FIFO logic evicted first.
    $world->spawn(
        (new EntityBuilder())
            ->at(8, 65, 8)
            ->with(new PositionComponent(8, 65, 8))
            ->with(new MetadataComponent(['entityType' => 'Player']))
            ->withTag(PlayerTag::class)
    );
    $kernel->run(1);

    // Force eviction of everything not guarded.
    $evicted = $unload->unloadUnusedChunks(1, 0);

    ok($evicted >= 4, "far chunks evicted ($evicted)");
    same(true, $store->isLoaded(0, 0), 'player-occupied chunk survived eviction');
    same(false, $store->isLoaded(120, 120), 'unguarded chunk was evicted');
});

exit(runTests());

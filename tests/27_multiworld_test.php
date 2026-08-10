<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\component\WorldComponent;
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\resource\WorldConfig;
use pocketmine\core\resource\WorldRegistry;
use pocketmine\port\driven\ChunkData;

/**
 * 14.20 - multi-world support.
 *
 * The ECS world is shared but each game-world is a bundle in the WorldRegistry
 * (own ChunkStore, WorldConfig, seed, storage folder). Entities carry a
 * WorldComponent and chunk/block/network services route by world id. These
 * tests lock in:
 *   (1) the default world bundle (id 0) exists at boot,
 *   (2) a second world gets its own store/config/seed - blocks never leak
 *       between worlds,
 *   (3) generated terrain differs across seeds (per-world generation),
 *   (4) WorldComponent is attached to spawned entities and routes the right
 *       store for block placement,
 *   (5) Server API lifecycle: generateWorld/loadWorld/getWorlds/unloadWorld.
 */

$kernel = \pocketmine\bootstrap();
$registry = $kernel->getWorldRegistry();
$world = $kernel->getWorld();
ok($registry instanceof WorldRegistry, 'WorldRegistry resource present');
if (!$registry instanceof WorldRegistry) {
    exit(runTests());
}

test('the default world bundle (id 0) is registered at boot', function () use ($registry): void {
    $world = $registry->getWorld(0);
    ok($world !== null, 'default world bundle exists');
    same('world', $world['name'] ?? '', 'default world named "world"');
    same('world', $world['folderName'] ?? '', 'default world folder "world"');
    same(1, $registry->count(), 'only the default world is loaded at boot');
});

test('a generated second world gets its own store, config and seed', function () use ($kernel, $registry): void {
    $server = \pocketmine\api\server\Server::getInstance();
    $second = $server->generateWorld('nether_test', 999);
    same('nether_test', $second->getName(), 'generateWorld returns the world facade');
    same(999, $second->getSeed(), 'generated world keeps the requested seed');
    same(2, $registry->count(), 'second world registered alongside default');

    // The two worlds use different ChunkStore instances.
    $store0 = $registry->getStore(0);
    $store1 = $registry->getStore($second->getWorldId());
    ok($store0 instanceof ChunkStore && $store1 instanceof ChunkStore, 'both worlds have stores');
    ok($store0 !== $store1, 'worlds do not share a ChunkStore');

    // And different WorldConfigs.
    $cfg0 = $registry->getConfig(0);
    $cfg1 = $registry->getConfig($second->getWorldId());
    ok($cfg0 instanceof WorldConfig && $cfg1 instanceof WorldConfig, 'both worlds have configs');
    ok($cfg0 !== $cfg1, 'worlds do not share a WorldConfig');
    same(999, $cfg1?->seed, 'second world config carries its seed');
});

test('blocks placed in one world never leak into the other', function () use ($kernel, $registry): void {
    $server = \pocketmine\api\server\Server::getInstance();
    $second = $server->getWorldByName('nether_test');
    ok($second !== null, 'getWorldByName finds the generated world');

    // Materialize chunk (0,0) in both worlds.
    $world0 = $server->getDefaultWorld();
    $world0->loadChunk(0, 0);
    $second->loadChunk(0, 0);

    // Place a marker block at the same coords in each world.
    $world0->setBlock(5, 70, 5, 1);   // stone in world 0
    $second->setBlock(5, 70, 5, 4);   // cobblestone in world 1

    same(1, $world0->getBlock(5, 70, 5), 'world 0 sees stone');
    same(4, $second->getBlock(5, 70, 5), 'world 1 sees cobblestone');
    same(1, $registry->getStore(0)->getBlock(5, 70, 5), 'world-0 store matches facade');
    same(4, $registry->getStore($second->getWorldId())->getBlock(5, 70, 5), 'world-1 store matches facade');
});

test('terrain generation is per-world (different seeds, different terrain)', function () use ($kernel, $registry): void {
    $server = \pocketmine\api\server\Server::getInstance();
    $world0 = $server->getDefaultWorld();
    $second = $server->getWorldByName('nether_test');

    // Load the same chunk in both worlds and compare raw block bytes.
    $a = $world0->getChunkData(0, 0);
    $b = $second->getChunkData(0, 0);
    ok($a instanceof ChunkData && $b instanceof ChunkData, 'both worlds generated chunk (0,0)');
    if (!$a instanceof ChunkData || !$b instanceof ChunkData) {
        return;
    }
    $blocksA = '';
    $blocksB = '';
    foreach ($a->sections as $s) { $blocksA .= (string)$s['blocks']; }
    foreach ($b->sections as $s) { $blocksB .= (string)$s['blocks']; }
    ok($blocksA !== $blocksB, 'chunk (0,0) terrain differs between seeds');
});

test('spawned entities carry a WorldComponent; block placement routes via it', function () use ($kernel, $registry): void {
    $server = \pocketmine\api\server\Server::getInstance();
    $second = $server->getWorldByName('nether_test');
    $worldId = $second->getWorldId();

    $mob = $kernel->getEntitySpawnService()->spawnMob('Zombie', 200, 65, 200, $worldId);
    $entity = $mob->getEntity();
    $wc = $entity?->get(WorldComponent::class);
    ok($wc instanceof WorldComponent, 'spawned mob has a WorldComponent');
    same($worldId, $wc?->id, 'mob belongs to the second world');

    // A player-less placement through the service with an explicit world: the
    // ECS player entity carries the world, and BlockPlaceService resolves the
    // store from it.
    $player = $kernel->getEntitySpawnService()->spawnMob('Zombie', 210, 65, 210, $worldId);
    $playerEntity = $player->getEntity();
    $playerWc = $playerEntity?->get(WorldComponent::class);
    if ($playerWc instanceof WorldComponent) {
        $playerWc->id = $worldId;
    }
    $inv = $playerEntity?->get(\pocketmine\core\component\InventoryComponent::class);
    $inv?->set(0, new \pocketmine\core\component\ItemStack(1, 0, 32)); // stone

    // Materialize chunk (13,13) in the second world, then place via the service.
    $second->loadChunk(13, 13);
    $kernel->getBlockPlaceService()->placeBlock($player, 216, 70, 216, 1, 1);
    same(1, $second->getBlock(216, 70, 216), 'block placed into the second world store');
    same(0, $server->getDefaultWorld()->getBlock(216, 70, 216), 'default world unaffected');
});

test('world metadata persists per folder and reloads through the Server API', function () use ($kernel, $registry): void {
    $server = \pocketmine\api\server\Server::getInstance();
    $second = $server->getWorldByName('nether_test');
    $worldId = $second->getWorldId();

    // Set a distinctive spawn + time and save through the kernel.
    $second->setSpawnLocation(123, 64, 456);
    $second->setTime(15000);
    $kernel->saveAllWorlds();

    // Unload then re-load: the bundle must come back with its meta.
    ok($server->unloadWorld('nether_test', false), 'unloadWorld removes the world');
    ok($registry->getWorld($worldId) === null, 'world unregistered');
    ok($registry->getWorldIdByName('nether_test') === null, 'name freed');

    $reloaded = $server->loadWorld('nether_test');
    ok($reloaded !== null, 'loadWorld restores the world from disk');
    same(999, $reloaded->getSeed(), 'seed restored from persisted meta');
    same(123, $reloaded->getSpawnLocation()['x'], 'spawn x restored');
    same(456, $reloaded->getSpawnLocation()['z'], 'spawn z restored');
    same(15000, $reloaded->getTime(), 'time restored');
});

runTests();

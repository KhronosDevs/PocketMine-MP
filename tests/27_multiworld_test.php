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

test('a void world generates air with a platform at spawn only', function () use ($kernel): void {
    $server = \pocketmine\api\server\Server::getInstance();
    $void = $server->generateWorld('void_test', 1234, 'void');
    same('void', $void->getGenerator(), 'world carries the void generator');

    // A far chunk is pure air.
    $far = $void->getChunkData(20, 20);
    ok($far instanceof ChunkData, 'void world generates far chunk');
    if ($far instanceof ChunkData) {
        $blocks = '';
        foreach ($far->sections as $s) { $blocks .= (string)$s['blocks']; }
        same(str_repeat("\x00", strlen($blocks)), $blocks, 'far void chunk is all air');
    }

    // The chunk holding the origin has a platform (grass on top of stone)
    // at the world origin column - everything around it stays air.
    $origin = $void->getChunkData(0, 0);
    ok($origin instanceof ChunkData, 'void world generates origin chunk');
    if ($origin instanceof ChunkData) {
        // World (0,0): grass top at y=64 on the platform.
        same(2, $void->getBlock(0, 64, 0), 'grass on the platform at origin');
        same(1, $void->getBlock(0, 63, 0), 'stone under the grass');
        // Off the 5x5 platform the column is air.
        same(0, $void->getBlock(10, 64, 10), 'air off the platform');
    }
});

test('a flat world generates its flat terrain (generator flows through ChunkLoadService)', function () use ($kernel): void {
    $server = \pocketmine\api\server\Server::getInstance();
    $flat = $server->generateWorld('flat_test', 99, 'flat');
    same('flat', $flat->getGenerator(), 'world carries the flat generator');
    $flat->loadChunk(0, 0);
    // Flat surface: grass at y=4, stone below, air above.
    same(2, $flat->getBlock(0, 4, 0), 'flat grass at y=4');
    same(1, $flat->getBlock(0, 3, 0), 'flat stone below grass');
    same(0, $flat->getBlock(0, 20, 0), 'air above the flat surface');
});

test('a void world persists its generator and restores it on load', function () use ($kernel, $registry): void {
    $server = \pocketmine\api\server\Server::getInstance();
    $void = $server->getWorldByName('void_test');
    ok($void !== null, 'void_test world exists');
    $void->loadChunk(0, 0);
    $kernel->saveAllWorlds();

    ok($server->unloadWorld('void_test', false), 'unload void_test');
    $reloaded = $server->loadWorld('void_test');
    ok($reloaded !== null, 'reload void_test from disk');
    same('void', $reloaded->getGenerator(), 'generator restored from persisted level.dat');
    same(1234, $reloaded->getSeed(), 'void seed restored');
    $reloaded->loadChunk(0, 0);
    same(2, $reloaded->getBlock(0, 64, 0), 'void platform survives the round-trip');
});

test('a foreign level.dat with no generator info loads as void (no regeneration)', function () use ($kernel): void {
    $server = \pocketmine\api\server\Server::getInstance();

    // Drop a world folder whose level.dat has no generatorName tag.
    $dir = 'worlds/foreign_test/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $nbt = new \pocketmine\nbt\NBT(\pocketmine\nbt\NBT::BIG_ENDIAN);
    $data = new \pocketmine\nbt\tag\CompoundTag('Data', []);
    $data->setLong('RandomSeed', 42);
    $data->setInt('SpawnX', 0);
    $data->setInt('SpawnY', 64);
    $data->setInt('SpawnZ', 0);
    $data->setByte('Difficulty', 1);
    $root = new \pocketmine\nbt\tag\CompoundTag('', []);
    $root->setTag('Data', $data);
    $nbt->setData($root);
    file_put_contents($dir . 'level.dat', $nbt->writeCompressed());

    $loaded = $server->loadWorld('foreign_test');
    ok($loaded !== null, 'foreign world loads');
    same('void', $loaded->getGenerator(), 'no generator info -> void default');
    // Terrain must not generate around the foreign world: a chunk loads as
    // air except the spawn platform.
    $loaded->loadChunk(3, 3);
    same(0, $loaded->getBlock(48, 64, 48), 'no terrain generated in foreign world');
});

test('a dropped world folder without any level.dat loads as void at boot', function () use ($kernel): void {
    // Simulate a hand-dropped lobby folder: no level.dat at all.
    $dir = 'worlds/no_meta_test/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $storage = \pocketmine\adapter\driven\storage\LevelProviderManager::create('worlds/', 'no_meta_test');
    ok($storage->worldFolderExists(), 'world folder exists on disk');
    same(null, $storage->loadWorldMeta(), 'no level.dat -> no meta');

    // applyPersistedWorldMeta is what the boot path runs: a folder that
    // exists but has no Khronos level.dat must default the generator to void.
    $registry = $kernel->getResourceRegistry();
    \pocketmine\applyPersistedWorldMeta($storage, $registry);
    $cfg = $registry->get(\pocketmine\core\resource\WorldConfig::class);
    same('void', $cfg?->generator, 'dropped folder without level.dat -> void');
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

test('chests and furnaces at the same coordinates in different worlds are isolated', function () use ($kernel, $registry): void {
    // Two worlds with a chest + furnace at the SAME block coordinates must
    // never share contents: the tile stores are per-world bundles now.
    $server = \pocketmine\api\server\Server::getInstance();
    $second = $server->getWorldByName('nether_test');
    if ($second === null) {
        $second = $server->generateWorld('nether_test', 999);
    }
    $worldId = $second->getWorldId();
    ok($worldId !== 0, 'second world has a non-default id');

    $chest0 = $registry->getChestStore(0);
    $chest1 = $registry->getChestStore($worldId);
    ok($chest0 !== null && $chest1 !== null, 'both worlds have chest stores');
    ok($chest0 !== $chest1, 'worlds do not share a ChestStore');

    $furnace0 = $registry->getFurnaceStore(0);
    $furnace1 = $registry->getFurnaceStore($worldId);
    ok($furnace0 !== null && $furnace1 !== null, 'both worlds have furnace stores');
    ok($furnace0 !== $furnace1, 'worlds do not share a FurnaceStore');

    // Same coordinates, different contents per world.
    $cx = 3333; $cy = 70; $cz = 3333;
    $chest0->get($cx, $cy, $cz)->set(0, new \pocketmine\core\component\ItemStack(1, 0, 1));   // 1 stone
    $chest1->get($cx, $cy, $cz)->set(0, new \pocketmine\core\component\ItemStack(5, 0, 64));   // 64 planks
    $furnace0->get($cx, $cy, $cz)['inventory']->set(0, new \pocketmine\core\component\ItemStack(15, 0, 3)); // 3 iron ore
    $furnace1->get($cx, $cy, $cz)['inventory']->set(0, new \pocketmine\core\component\ItemStack(16, 0, 1)); // 1 coal ore

    $c0 = $chest0->get($cx, $cy, $cz)->get(0);
    $c1 = $chest1->get($cx, $cy, $cz)->get(0);
    ok($c0 !== null && $c0->itemId === 1 && $c0->count === 1, 'default-world chest holds its own item');
    ok($c1 !== null && $c1->itemId === 5 && $c1->count === 64, 'second-world chest holds its own item');

    $f0 = $furnace0->get($cx, $cy, $cz)['inventory']->get(0);
    $f1 = $furnace1->get($cx, $cy, $cz)['inventory']->get(0);
    ok($f0 !== null && $f0->itemId === 15, 'default-world furnace holds its own input');
    ok($f1 !== null && $f1->itemId === 16, 'second-world furnace holds its own input');

    // Per-world snapshot export: a chunk's snapshots come only from that
    // world's store, so saving/loading a world can never cross-wire contents.
    $chunkX = intdiv($cx, 16);
    $chunkZ = intdiv($cz, 16);
    $snaps0 = $chest0->snapshotsForChunk($chunkX, $chunkZ);
    $snaps1 = $chest1->snapshotsForChunk($chunkX, $chunkZ);
    same(1, count($snaps0), 'default world exports exactly its chest');
    same(1, count($snaps1), 'second world exports exactly its chest');
    $decoded0 = json_decode((string)base64_decode($snaps0[0]->data['nbt'] ?? ''), true);
    $decoded1 = json_decode((string)base64_decode($snaps1[0]->data['nbt'] ?? ''), true);
    same(1, $decoded0[0]['id'] ?? null, 'snapshot 0 carries the default-world item');
    same(5, $decoded1[0]['id'] ?? null, 'snapshot 1 carries the second-world item');
});

runTests();

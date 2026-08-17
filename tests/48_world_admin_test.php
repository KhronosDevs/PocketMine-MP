<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\api\command\ConsoleCommandSender;

/**
 * World admin (14.33):
 *   (1) legacy level.dat files with IntTag Time (0.15-era PocketMine saves)
 *       keep their spawn instead of being treated as fresh worlds,
 *   (2) /world load + /world unload manage on-disk world folders,
 *   (3) /world list shows unloaded folders too,
 *   (4) /setspawn pins the world spawn and persists it.
 */

$kernel = \pocketmine\bootstrap();
$port = $kernel->getCommandPort();
$console = new ConsoleCommandSender();
$server = \pocketmine\api\server\Server::getInstance();

test('an old IntTag-Time level.dat keeps its spawn (funil-style world)', function () use ($kernel, $server): void {
    $name = 'legacy_int_spawn';
    $dir = 'worlds/' . $name . '/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    // Write a 0.15-era level.dat: Time and RandomSeed as IntTag (older
    // PocketMine writes), SpawnX/Y/Z as IntTag - exactly what funil's file
    // looks like. The adapter must read it, not bail out to a fresh world.
    $nbt = new \pocketmine\nbt\NBT(\pocketmine\nbt\NBT::BIG_ENDIAN);
    $data = new \pocketmine\nbt\tag\CompoundTag('Data', []);
    $data->setTag('RandomSeed', new \pocketmine\nbt\tag\IntTag('RandomSeed', 777));
    $data->setTag('SpawnX', new \pocketmine\nbt\tag\IntTag('SpawnX', 126));
    $data->setTag('SpawnY', new \pocketmine\nbt\tag\IntTag('SpawnY', 17));
    $data->setTag('SpawnZ', new \pocketmine\nbt\tag\IntTag('SpawnZ', 126));
    $data->setTag('Time', new \pocketmine\nbt\tag\IntTag('Time', 6001));
    $data->setByte('Difficulty', 1);
    $root = new \pocketmine\nbt\tag\CompoundTag('', []);
    $root->setTag('Data', $data);
    $nbt->setData($root);
    file_put_contents($dir . 'level.dat', $nbt->writeCompressed());

    $storage = \pocketmine\adapter\driven\storage\LevelProviderManager::create('worlds/', $name);
    $meta = $storage->loadWorldMeta();
    ok($meta !== null, 'legacy IntTag level.dat parses');
    if ($meta === null) {
        return;
    }
    same('777', $meta['seed'] ?? '', 'RandomSeed read from IntTag');
    same('126', $meta['spawnX'] ?? '', 'SpawnX read from IntTag');
    same('17', $meta['spawnY'] ?? '', 'SpawnY read from IntTag');
    same('126', $meta['spawnZ'] ?? '', 'SpawnZ read from IntTag');
    same('6001', $meta['time'] ?? '', 'Time read from IntTag');

    // And the full Server API path: loadWorld restores that spawn.
    $loaded = $server->loadWorld($name);
    ok($loaded !== null, 'legacy world loads through the Server API');
    $spawn = $loaded->getSpawnLocation();
    same(126, $spawn['x'], 'loaded world spawn x preserved');
    same(17, $spawn['y'], 'loaded world spawn y preserved');
    same(126, $spawn['z'], 'loaded world spawn z preserved');
    same(777, $loaded->getSeed(), 'loaded world seed preserved');
});

test('/world list shows unloaded on-disk folders', function () use ($port, $console): void {
    // legacy_int_spawn is loaded now; make sure it is NOT listed as unloaded.
    $unloaded = \pocketmine\api\server\Server::getInstance()->getUnloadedWorlds();
    ok(!in_array('legacy_int_spawn', $unloaded, true), 'loaded world not listed as unloaded');

    // A real unloaded world folder (no registry entry) IS listed.
    $dir = 'worlds/unloaded_folder_probe/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        mkdir($dir . 'region', 0755, true);
    }
    $listed = \pocketmine\api\server\Server::getInstance()->getUnloadedWorlds();
    ok(in_array('unloaded_folder_probe', $listed, true), 'folder without registry entry listed as unloaded');
    ok($port->execute($console, 'world list'), '/world list executes');
});

test('/world load brings an unloaded folder into the registry', function () use ($port, $console, $kernel): void {
    $server = \pocketmine\api\server\Server::getInstance();
    // Drop a folder that is not registered, then /world load it.
    $dir = 'worlds/load_me_test/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $nbt = new \pocketmine\nbt\NBT(\pocketmine\nbt\NBT::BIG_ENDIAN);
    $data = new \pocketmine\nbt\tag\CompoundTag('Data', []);
    $data->setLong('RandomSeed', 555);
    $data->setInt('SpawnX', 10);
    $data->setInt('SpawnY', 70);
    $data->setInt('SpawnZ', 20);
    $data->setLong('Time', 1000);
    $data->setByte('Difficulty', 1);
    $root = new \pocketmine\nbt\tag\CompoundTag('', []);
    $root->setTag('Data', $data);
    $nbt->setData($root);
    file_put_contents($dir . 'level.dat', $nbt->writeCompressed());

    ok($port->execute($console, 'world load load_me_test'), '/world load executes');
    $loaded = $server->getWorldByName('load_me_test');
    ok($loaded !== null, 'world is in the registry after /world load');
    $spawn = $loaded->getSpawnLocation();
    same(10, $spawn['x'], 'loaded world spawn restored');
    same(555, $loaded->getSeed(), 'loaded world seed restored');
});

test('/world unload saves and removes a world (not the default)', function () use ($port, $console, $server): void {
    ok($server->getWorldByName('load_me_test') !== null, 'load_me_test present before unload');
    ok($port->execute($console, 'world unload load_me_test'), '/world unload executes');
    ok($server->getWorldByName('load_me_test') === null, 'world unregistered after /world unload');

    // Default world cannot be unloaded.
    ok(!$port->execute($console, 'world unload world'), 'default world refuses /world unload');
});

/** Spawn a PlayerTag entity + op it, returning a working PlayerCommandSender. */
function spawnOpPlayerForSetSpawn(\pocketmine\Kernel $kernel, float $x, float $y, float $z, int $worldId = 0, string $name = 'setspawn_admin'): \pocketmine\api\command\PlayerCommandSender {
    $meta = new \pocketmine\core\component\MetadataComponent();
    $meta->set(\pocketmine\core\constants\MetadataKeys::USERNAME, $name);
    $meta->set(\pocketmine\core\constants\MetadataKeys::UNIQUE_ID, bin2hex(random_bytes(8)));
    $ref = $kernel->getWorld()->spawn(
        (new \pocketmine\core\ecs\EntityBuilder())
            ->at($x, $y, $z)
            ->with(new \pocketmine\core\component\HealthComponent(20, 20))
            ->with($meta)
            ->with(new \pocketmine\core\component\WorldComponent($worldId))
            ->withTag(\pocketmine\core\component\tags\PlayerTag::class)
    );
    $id = $ref->getId();
    // Grant op exactly like OpCommand does: ops.txt + live metadata.
    $lists = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\PlayerListManager::class);
    $lists?->addOp($name);
    $meta->set('permissions', ['pocketmine.op']);
    return new \pocketmine\api\command\PlayerCommandSender($id, $name);
}

test('/setspawn pins the world spawn in the config', function () use ($port, $kernel, $server): void {
    $world = $server->getDefaultWorld();
    $sender = spawnOpPlayerForSetSpawn($kernel, 312, 71, 415);

    ok($port->execute($sender, 'setspawn'), '/setspawn executes');
    $spawn = $world->getSpawnLocation();
    same(312, $spawn['x'], 'spawn x pinned to player x');
    same(71, $spawn['y'], 'spawn y pinned to player y');
    same(415, $spawn['z'], 'spawn z pinned to player z');

    // Default-world join/respawn read the ServerConfig: it must mirror.
    $config = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ServerConfig::class);
    same(312, $config?->spawnX, 'ServerConfig spawn x mirrored');
    same(415, $config?->spawnZ, 'ServerConfig spawn z mirrored');
});

test('/setspawn on a non-default world writes only that world config', function () use ($port, $kernel, $server): void {
    $second = $server->getWorldByName('legacy_int_spawn');
    ok($second !== null, 'legacy world still loaded');
    $worldId = $second->getWorldId();
    ok($worldId !== 0, 'second world id is non-zero');

    $defaultBefore = $server->getDefaultWorld()->getSpawnLocation();
    $sender = spawnOpPlayerForSetSpawn($kernel, 500, 66, 600, $worldId, 'setspawn_admin2');

    ok($port->execute($sender, 'setspawn'), '/setspawn executes in second world');
    $spawn = $second->getSpawnLocation();
    same(500, $spawn['x'], 'second world spawn x pinned');
    same(66, $spawn['y'], 'second world spawn y pinned');
    same(600, $spawn['z'], 'second world spawn z pinned');

    // The default world spawn must be untouched.
    $default = $server->getDefaultWorld()->getSpawnLocation();
    same($defaultBefore['x'], $default['x'], 'default world spawn x unchanged');
    same($defaultBefore['z'], $default['z'], 'default world spawn z unchanged');
});

runTests();

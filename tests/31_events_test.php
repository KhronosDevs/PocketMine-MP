<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\api\event\BlockBreakEvent;
use pocketmine\api\event\BlockPlaceEvent;
use pocketmine\api\event\EntitySpawnEvent;
use pocketmine\api\event\PlayerInteractEvent;
use pocketmine\api\event\PlayerJoinEvent;
use pocketmine\api\event\PlayerLeaveEvent;
use pocketmine\api\event\PlayerRespawnEvent;
use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\ItemStack;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\tags\DeadTag;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;
use pocketmine\core\resource\ChunkStore;
use pocketmine\port\driven\PlayerRef;

/**
 * Blocker 4: the gameplay events wired into the services. Every event class
 * that existed in api/event but never fired is now emitted at the right point:
 * join/leave/respawn, block break/place (cancellable), entity spawn, and
 * interact/attack (cancellable). Chat + move events are covered over the wire
 * in tests/17 (they only fire inside NetworkSessionService).
 *
 * Each listener guards on a per-test player/entity name so handlers registered
 * in one test never fire for another (all tests share one process).
 */

$kernel = \pocketmine\bootstrap();
$world = $kernel->getWorld();
$eventPort = $kernel->getEventPort();

function flushWorld(World $world): void {
    $world->tick(0.05);
}

/** Spawn a player-tagged entity with a username + uniqueId (gamemode 0). */
function spawnPlayer(World $world, string $name, float $x, float $y, float $z): EntityRef {
    return $world->spawn(
        (new EntityBuilder())
            ->at($x, $y, $z)
            ->with(new HealthComponent(20, 20))
            ->with(new MetadataComponent(['username' => $name, 'uniqueId' => 'uuid-' . $name, 'gamemode' => 0]))
            ->with(new InventoryComponent(36))
            ->withTag(PlayerTag::class)
    );
}

/** A hostile target without AI (a plain entity with health + type). */
function spawnTarget(World $world, float $x, float $z): EntityRef {
    return $world->spawn(
        (new EntityBuilder())
            ->at($x, 65, $z)
            ->with(new HealthComponent(20, 20))
            ->with(new MetadataComponent(['entityType' => 'Zombie']))
    );
}

test('PlayerJoinEvent fires on handleJoin with the joined player', function () use ($kernel, $world, $eventPort): void {
    $fired = 0;
    $eventPort->subscribe(PlayerJoinEvent::class, function (PlayerJoinEvent $e) use (&$fired): void {
        if ($e->getPlayer()->getName() !== 'JoinEve') {
            return;
        }
        $fired++;
        ok($e->getPlayer()->getUniqueId() !== '', 'event player carries a unique id');
    });

    $entityRef = $kernel->getPlayerJoinService()->handleJoin(
        new PlayerRef('uuid-joineve', -1, 'JoinEve'),
        'JoinEve',
    );
    same(1, $fired, 'PlayerJoinEvent fired exactly once');
    same('JoinEve', $entityRef->getEntity()?->get(MetadataComponent::class)?->get('username'), 'player entity created');

    $kernel->getPlayerLeaveService()->handleLeave($entityRef, 'test done');
    flushWorld($world);
});

test('PlayerLeaveEvent fires on handleLeave with the reason', function () use ($kernel, $world, $eventPort): void {
    $player = spawnPlayer($world, 'LeaveEve', 400, 65, 400);
    $fired = 0;
    $eventPort->subscribe(PlayerLeaveEvent::class, function (PlayerLeaveEvent $e) use (&$fired): void {
        if ($e->getPlayer()->getName() !== 'LeaveEve') {
            return;
        }
        $fired++;
        same('quitting', $e->getReason(), 'leave reason carried');
    });

    $kernel->getPlayerLeaveService()->handleLeave($player, 'quitting');
    same(1, $fired, 'PlayerLeaveEvent fired');
    flushWorld($world);
    ok(!$player->isValid(), 'player despawned after leave');
});

test('PlayerRespawnEvent fires on respawn and the player is revived', function () use ($kernel, $world, $eventPort): void {
    $player = spawnPlayer($world, 'RespawnEve', 410, 65, 410);
    $player->getEntity()?->set(DeadTag::class, new DeadTag());
    $health = $player->getEntity()?->get(HealthComponent::class);
    if ($health !== null) {
        $health->current = 0;
    }

    $fired = 0;
    $eventPort->subscribe(PlayerRespawnEvent::class, function (PlayerRespawnEvent $e) use (&$fired): void {
        if ($e->getPlayer()->getName() !== 'RespawnEve') {
            return;
        }
        $fired++;
    });

    $kernel->getPlayerRespawnService()->respawn($player);
    same(1, $fired, 'PlayerRespawnEvent fired');
    ok(!$player->getEntity()?->has(DeadTag::class), 'dead tag removed on respawn');
    near(20.0, $player->getEntity()?->get(HealthComponent::class)->current, 1e-9, 'health restored');

    $kernel->getPlayerLeaveService()->handleLeave($player, 'test done');
    flushWorld($world);
});

test('BlockBreakEvent fires and can cancel the break', function () use ($kernel, $world, $eventPort): void {
    $store = $world->getResourceRegistry()->get(ChunkStore::class);
    ok($store instanceof ChunkStore, 'chunk store present');
    $kernel->getChunkLoadService()->loadChunk(0, 0);
    $store->setBlock(10, 70, 10, 1, 0); // stone

    $player = spawnPlayer($world, 'BreakEve', 10.5, 71, 10.5);
    $eventPort->subscribe(BlockBreakEvent::class, function (BlockBreakEvent $e): void {
        $b = $e->getBlock();
        if ($e->getPlayer()->getName() === 'BreakEve' && $b->getX() === 10 && $b->getY() === 70 && $b->getZ() === 10) {
            $e->setCancelled(true);
        }
    });

    same(false, $kernel->getBlockBreakService()->breakBlock($player, 10, 70, 10, 1), 'cancelled break returns false');
    same(1, $store->getBlock(10, 70, 10), 'block still stone after cancelled break');

    $kernel->getPlayerLeaveService()->handleLeave($player, 'test done');
    flushWorld($world);
});

test('a non-cancelled break removes the block', function () use ($kernel, $world, $eventPort): void {
    $store = $world->getResourceRegistry()->get(ChunkStore::class);
    $kernel->getChunkLoadService()->loadChunk(1, 1);
    $store->setBlock(20, 70, 20, 3, 0); // dirt

    $player = spawnPlayer($world, 'BreakBob', 20.5, 71, 20.5);
    same(true, $kernel->getBlockBreakService()->breakBlock($player, 20, 70, 20, 1), 'break lands');
    same(0, $store->getBlock(20, 70, 20), 'block is air after break');

    $kernel->getPlayerLeaveService()->handleLeave($player, 'test done');
    flushWorld($world);
});

test('BlockPlaceEvent fires and can cancel the placement (item kept)', function () use ($kernel, $world, $eventPort): void {
    $store = $world->getResourceRegistry()->get(ChunkStore::class);
    $kernel->getChunkLoadService()->loadChunk(0, 0);
    $store->setBlock(12, 70, 12, 0, 0); // air

    $player = spawnPlayer($world, 'PlaceEve', 12.5, 71, 12.5);
    $player->getEntity()?->get(InventoryComponent::class)->set(0, new ItemStack(5, 0, 8)); // planks

    $eventPort->subscribe(BlockPlaceEvent::class, function (BlockPlaceEvent $e): void {
        $b = $e->getBlock();
        if ($e->getPlayer()->getName() === 'PlaceEve' && $b->getX() === 12 && $b->getY() === 70 && $b->getZ() === 12) {
            $e->setCancelled(true);
        }
    });

    same(false, $kernel->getBlockPlaceService()->placeBlock($player, 12, 70, 12, 1, 5, 0), 'cancelled place returns false');
    same(0, $store->getBlock(12, 70, 12), 'no block placed');
    $item = $player->getEntity()?->get(InventoryComponent::class)->get(0);
    same(8, $item?->count, 'inventory untouched on cancelled place');

    $kernel->getPlayerLeaveService()->handleLeave($player, 'test done');
    flushWorld($world);
});

test('a non-cancelled placement sets the block and consumes the item', function () use ($kernel, $world): void {
    $store = $world->getResourceRegistry()->get(ChunkStore::class);
    $kernel->getChunkLoadService()->loadChunk(1, 1);
    $store->setBlock(22, 70, 22, 0, 0); // air

    $player = spawnPlayer($world, 'PlaceBob', 22.5, 71, 22.5);
    $player->getEntity()?->get(InventoryComponent::class)->set(0, new ItemStack(5, 0, 8));

    same(true, $kernel->getBlockPlaceService()->placeBlock($player, 22, 70, 22, 1, 5, 0), 'placement lands');
    same(5, $store->getBlock(22, 70, 22), 'planks placed');
    $item = $player->getEntity()?->get(InventoryComponent::class)->get(0);
    same(7, $item?->count, 'one plank consumed');

    $kernel->getPlayerLeaveService()->handleLeave($player, 'test done');
    flushWorld($world);
});

test('EntitySpawnEvent fires for mobs and item drops', function () use ($kernel, $world, $eventPort): void {
    $mobs = 0;
    $items = 0;
    $eventPort->subscribe(EntitySpawnEvent::class, function (EntitySpawnEvent $e) use (&$mobs, &$items): void {
        $type = $e->getEntity()->getMetadata()->get('entityType');
        if ($type === 'Zombie') {
            $mobs++;
        }
        if ($type === 'item') {
            $items++;
        }
    });

    $kernel->getEntitySpawnService()->spawnMob('Zombie', 500, 65, 500);
    $kernel->getEntitySpawnService()->spawnItem(501, 65, 501, new ItemStack(1, 0, 1));
    same(1, $mobs, 'EntitySpawnEvent fired for the mob');
    same(1, $items, 'EntitySpawnEvent fired for the item drop');

    foreach ($world->getEntities() as $entity) {
        $world->despawn($entity);
    }
    flushWorld($world);
});

test('PlayerInteractEvent fires on attack and can cancel it', function () use ($kernel, $world, $eventPort): void {
    $attacker = spawnPlayer($world, 'FightEve', 510, 65, 510);
    $target = spawnTarget($world, 511, 510);
    $interaction = $kernel->getEntityInteractionService();

    $eventPort->subscribe(PlayerInteractEvent::class, function (PlayerInteractEvent $e): void {
        if ($e->getPlayer()->getName() === 'FightEve') {
            $e->setCancelled(true);
        }
    });

    same(false, $interaction->attack($attacker, $target), 'cancelled attack returns false');
    near(20.0, $target->getEntity()?->get(HealthComponent::class)->current, 1e-9, 'no damage on cancelled attack');
    same(false, $interaction->interact($attacker, $target), 'cancelled interact returns false');

    $kernel->getPlayerLeaveService()->handleLeave($attacker, 'test done');
    $world->despawn($target->getEntity());
    flushWorld($world);
});

test('a non-cancelled attack deals damage through the combat pipeline', function () use ($kernel, $world): void {
    $attacker = spawnPlayer($world, 'FightBob', 520, 65, 520);
    $target = spawnTarget($world, 521, 520);

    same(true, $kernel->getEntityInteractionService()->attack($attacker, $target), 'attack landed');
    near(19.0, $target->getEntity()?->get(HealthComponent::class)->current, 1e-9, '1 damage dealt (base, bare hands)');

    $kernel->getPlayerLeaveService()->handleLeave($attacker, 'test done');
    $world->despawn($target->getEntity());
    flushWorld($world);
});

test('loadPluginsFromDirectory scans plugin.yml dirs and .phar, skips .jar', function () use ($kernel): void {
    $tmp = sys_get_temp_dir() . '/khronos_scan_' . getmypid();
    if (is_dir($tmp)) {
        // Nuke a previous failed run.
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($tmp);
    }
    mkdir($tmp, 0755, true);

    // A directory plugin with a renamed identity so it is not a duplicate.
    $src = __DIR__ . '/fixtures/demo-plugin';
    $dest = $tmp . '/DemoScan';
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        $target = $dest . DIRECTORY_SEPARATOR . $it->getSubPathName();
        if ($file->isDir()) {
            mkdir($target, 0755, true);
        } else {
            @mkdir(dirname($target), 0755, true);
            copy($file->getPathname(), $target);
        }
    }
    $yml = (string)file_get_contents($dest . '/plugin.yml');
    file_put_contents($dest . '/plugin.yml', str_replace('name: Demo', 'name: DemoScan', $yml));

    // A .jar archive must be ignored (Java binary, not a PHP plugin).
    file_put_contents($tmp . '/evil.jar', 'not a php plugin');
    // A stray .txt file must be ignored too.
    file_put_contents($tmp . '/README.txt', 'hello');

    $loaded = $kernel->loadPluginsFromDirectory($tmp);
    same(1, $loaded, 'exactly the directory plugin loaded (jar + txt skipped)');
    ok($kernel->getPluginManager()->getPlugin('DemoScan') !== null, 'DemoScan is loaded');
    ok($kernel->getPluginManager()->isPluginEnabled('DemoScan'), 'DemoScan is enabled');
});

exit(runTests());

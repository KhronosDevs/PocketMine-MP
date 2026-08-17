<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\api\event\ChunkLoadEvent;
use pocketmine\api\event\ChunkUnloadEvent;
use pocketmine\api\event\DataPacketReceiveEvent;
use pocketmine\api\event\DataPacketSendEvent;
use pocketmine\api\event\EntityMotionEvent;
use pocketmine\api\event\InventoryTransactionEvent;
use pocketmine\api\event\ItemDespawnEvent;
use pocketmine\api\event\ItemSpawnEvent;
use pocketmine\api\event\PlayerAnimationEvent;
use pocketmine\api\event\PlayerItemHeldEvent;
use pocketmine\api\event\PlayerPreLoginEvent;
use pocketmine\api\event\PlayerQuitEvent;
use pocketmine\api\event\ProjectileHitEvent;
use pocketmine\api\event\ProjectileLaunchEvent;
use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\ItemStack;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\component\WorldComponent;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;
use pocketmine\port\driven\PlayerRef;

/**
 * Events breadth audit (second wave): the service-level events that fire
 * outside the network path - chunk load/unload, item spawn/despawn, entity
 * motion, player quit - plus construction/cancellation checks for the
 * session-driven events whose firing lives in tests/17 (they need a real
 * wire client). Every listener guards on a per-test marker so handlers
 * never cross-fire in one process.
 */

$kernel = \pocketmine\bootstrap();
$world = $kernel->getWorld();
$eventPort = $kernel->getEventPort();

function spawnPlayer3(World $world, string $name, float $x, float $y, float $z): EntityRef {
    return $world->spawn(
        (new EntityBuilder())
            ->at($x, $y, $z)
            ->with(new HealthComponent(20, 20))
            ->with(new MetadataComponent(['username' => $name, 'uniqueId' => 'uuid-' . $name, 'gamemode' => 0]))
            ->with(new InventoryComponent(36))
            ->with(new WorldComponent(0))
            ->withTag(PlayerTag::class)
    );
}

function wrapPlayer3(World $world, EntityRef $ref): \pocketmine\api\entity\Player {
    $entity = \pocketmine\api\entity\Entity::wrap($ref, $world);
    return $entity instanceof \pocketmine\api\entity\Player
        ? $entity
        : new \pocketmine\api\entity\Player($ref, $world);
}

test('PlayerQuitEvent fires in handleLeave alongside PlayerLeaveEvent', function () use ($kernel, $world, $eventPort): void {
    $quit = 0;
    $eventPort->subscribe(PlayerQuitEvent::class, function (PlayerQuitEvent $e) use (&$quit): void {
        if ($e->getPlayer()->getName() === 'QuitMarker') {
            $quit++;
            same('bye', $e->getQuitMessage(), 'quit message carries the reason');
        }
    });
    $ref = spawnPlayer3($world, 'QuitMarker', 600, 65, 600);
    $kernel->getPlayerLeaveService()->handleLeave($ref, 'bye');
    same(1, $quit, 'PlayerQuitEvent fired once');
    $world->tick(0.05);
});

test('ChunkLoadEvent fires for newly loaded chunks and flags new ones', function () use ($kernel, $eventPort): void {
    // A fresh far coordinate each run: a previous run may have persisted the
    // chunk to disk, which would make it a disk load (isNewChunk=false).
    $cx = 50000 + mt_rand(0, 50000);
    $cz = 50000 + mt_rand(0, 50000);
    $loads = [];
    $eventPort->subscribe(ChunkLoadEvent::class, function (ChunkLoadEvent $e) use (&$loads, $cx, $cz): void {
        if ($e->getChunkX() === $cx && $e->getChunkZ() === $cz) {
            $loads[] = [$e->getChunkX(), $e->getChunkZ(), $e->getWorldId(), $e->isNewChunk()];
        }
    });
    $kernel->getChunkLoadService()->loadChunk($cx, $cz);
    same(1, count($loads), 'ChunkLoadEvent fired once for the loaded chunk');
    same([$cx, $cz, 0, true], $loads[0] ?? null, 'coords + worldId + newChunk flag');
});

test('ChunkUnloadEvent fires on unload and cancellation keeps the chunk resident', function () use ($kernel, $eventPort): void {
    $unloads = 0;
    $eventPort->subscribe(ChunkUnloadEvent::class, function (ChunkUnloadEvent $e) use (&$unloads): void {
        if ($e->getChunkX() === 43211 && $e->getChunkZ() === 43211) {
            $unloads++;
            $e->setCancelled(true); // keep it resident
        }
    });
    $kernel->getChunkLoadService()->loadChunk(43211, 43211);
    $kernel->getChunkUnloadService()->unloadChunk(43211, 43211);
    same(1, $unloads, 'ChunkUnloadEvent fired');
    // Cancelled: the chunk must still be resident in the store.
    $store = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);
    ok($store instanceof \pocketmine\core\resource\ChunkStore && $store->isLoaded(43211, 43211), 'cancelled unload keeps the chunk');
});

test('ItemSpawnEvent fires in spawnItem and ItemDespawnEvent is cancellable', function () use ($kernel, $world, $eventPort): void {
    $spawns = 0;
    $despawns = 0;
    $itemId = null;
    $cancelDespawns = true; // cancel the first attempt only
    $eventPort->subscribe(ItemSpawnEvent::class, function (ItemSpawnEvent $e) use (&$spawns): void {
        $pos = $e->getEntity()->getPosition();
        if ($pos !== null && abs($pos->x - 701) < 1 && abs($pos->z - 701) < 1 && abs($pos->y - 81) < 1) {
            $spawns++;
        }
    });
    $eventPort->subscribe(ItemDespawnEvent::class, function (ItemDespawnEvent $e) use (&$despawns, &$itemId, &$cancelDespawns): void {
        if ($itemId !== null && $e->getEntity()->getId() === $itemId) {
            $despawns++;
            if ($cancelDespawns) {
                $e->setCancelled(true);
            }
        }
    });
    $kernel->getEntitySpawnService()->spawnItem(701, 81, 701, new ItemStack(1, 0, 3));
    // Item ids are assigned by the ECS; find the spawned one by position.
    $found = null;
    foreach ($world->query()->with(PositionComponent::class, MetadataComponent::class)->build() as $e) {
        $pos = $e->get(PositionComponent::class);
        if ($pos !== null && abs($pos->x - 701) < 1 && abs($pos->z - 701) < 1 && abs($pos->y - 81) < 1) {
            $found = $e;
            break;
        }
    }
    ok($found !== null, 'item entity exists in the world');
    same(1, $spawns, 'ItemSpawnEvent fired once');
    if ($found !== null) {
        $itemId = $found->id;
        $kernel->getEntityDespawnService()->despawn(EntityRef::create($itemId, $world), false);
        $world->tick(0.05); // flush the deferred removal
        ok($world->getEntity($itemId) !== null, 'cancelled ItemDespawnEvent keeps the item');
        $cancelDespawns = false; // second attempt: let the despawn proceed
        $kernel->getEntityDespawnService()->despawn(EntityRef::create($itemId, $world), false);
        $world->tick(0.05); // flush the deferred removal
        ok($world->getEntity($itemId) === null, 'uncancelled despawn removes the item');
        same(2, $despawns, 'ItemDespawnEvent fired for both attempts');
    }
});

test('EntityMotionEvent fires on knockback and cancellation suppresses it', function () use ($kernel, $world, $eventPort): void {
    $motions = 0;
    $eventPort->subscribe(EntityMotionEvent::class, function (EntityMotionEvent $e) use (&$motions): void {
        $pos = $e->getEntity()->getPosition();
        if ($pos !== null && abs($pos->x - 802) < 1 && abs($pos->z - 802) < 1 && abs($pos->y - 82) < 1) {
            $motions++;
            $e->setCancelled(true);
        }
    });
    $target = $world->spawn(
        (new EntityBuilder())
            ->at(802, 82, 802)
            ->with(new VelocityComponent())
            ->with(new MetadataComponent())
            ->with(new WorldComponent(0))
    );
    $attacker = $world->spawn(
        (new EntityBuilder())
            ->at(803, 82, 802)
            ->with(new MetadataComponent())
            ->with(new WorldComponent(0))
    );
    $kernel->getKnockbackService()->applyKnockback($attacker, $target, 2.0);
    same(1, $motions, 'EntityMotionEvent fired');
    $vel = $target->getEntity()?->get(VelocityComponent::class);
    same(0.0, $vel?->x ?? 1.0, 'cancelled knockback leaves velocity untouched');
    $world->tick(0.05);
});

test('session-driven events are constructible and cancellable', function () use ($kernel, $world, $eventPort): void {
    $ref = spawnPlayer3($world, 'CtorCheck', 900, 65, 900);
    $player = wrapPlayer3($world, $ref);

    // PlayerItemHeldEvent
    $ev = new PlayerItemHeldEvent($player, \pocketmine\api\inventory\ItemStack::fromCore(new ItemStack(4, 0, 1)), 2, 2);
    $ev->setCancelled(true);
    ok($ev->isCancelled(), 'PlayerItemHeldEvent cancellable');
    same(2, $ev->getSlot(), 'PlayerItemHeldEvent slot getter');
    same(4, $ev->getItem()?->getId(), 'PlayerItemHeldEvent item getter');

    // PlayerAnimationEvent
    $anim = new PlayerAnimationEvent($player, PlayerAnimationEvent::ARM_SWING);
    $anim->setCancelled(true);
    ok($anim->isCancelled(), 'PlayerAnimationEvent cancellable');
    same(PlayerAnimationEvent::ARM_SWING, $anim->getAnimationType(), 'animation type getter');

    // PlayerPreLoginEvent
    $pre = new PlayerPreLoginEvent('Prelogin', '1.2.3.4');
    $pre->setKickMessage('No entry');
    $pre->setCancelled(true);
    ok($pre->isCancelled(), 'PlayerPreLoginEvent cancellable');
    same('Prelogin', $pre->getUsername(), 'prelogin username');
    same('1.2.3.4', $pre->getAddress(), 'prelogin address');
    same('No entry', $pre->getKickMessage(), 'prelogin kick message settable');

    // InventoryTransactionEvent
    $tx = new InventoryTransactionEvent($player, 0, 5);
    $tx->setCancelled(true);
    ok($tx->isCancelled(), 'InventoryTransactionEvent cancellable');
    same(5, $tx->getSlot(), 'tx slot getter');

    // ProjectileLaunchEvent / ProjectileHitEvent
    $launch = new ProjectileLaunchEvent(\pocketmine\api\entity\Entity::wrap($ref, $world), $player);
    $launch->setCancelled(true);
    ok($launch->isCancelled(), 'ProjectileLaunchEvent cancellable');
    ok($launch->getShooter() === $player, 'launch shooter getter');
    $hit = new ProjectileHitEvent(\pocketmine\api\entity\Entity::wrap($ref, $world), null, 1.0, 2.0, 3.0);
    same(2.0, $hit->getHitY(), 'ProjectileHitEvent position getter');
    same(null, $hit->getHitEntity(), 'ProjectileHitEvent null entity for block hit');

    // DataPacketReceiveEvent / DataPacketSendEvent
    $recv = new DataPacketReceiveEvent($player, 0x12, "\x12abc");
    $recv->setCancelled(true);
    ok($recv->isCancelled(), 'DataPacketReceiveEvent cancellable');
    same(0x12, $recv->getPacketId(), 'receive packet id getter');
    $send = new DataPacketSendEvent($player, new \pocketmine\protocol\TextPacket());
    $send->setCancelled(true);
    ok($send->isCancelled(), 'DataPacketSendEvent cancellable');
    ok($send->getPacket() instanceof \pocketmine\protocol\TextPacket, 'send packet getter');

    $kernel->getPlayerLeaveService()->handleLeave($ref, 'done');
    $world->tick(0.05);
});

exit(runTests());

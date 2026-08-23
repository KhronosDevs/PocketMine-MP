<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\ItemStack;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;

/**
 * Phase 14.5 - item pickup.
 *
 * Dropped item entities (EntitySpawnService::spawnItem) are collected by a
 * nearby alive player through EntityInteractionService::pickup - driven both
 * by the per-tick ItemPickupSystem (walk-over) and by right-clicking the
 * entity. This proves: (1) the pickup delay keeps fresh drops on the ground
 * for ~10 ticks, (2) a walk-over pickup moves the stack into the inventory
 * and despawns the entity, (3) picked-up stacks merge onto existing stacks,
 * (4) a full inventory leaves the item on the ground untouched, (5) the
 * right-click interact() route dispatches to pickup.
 */

$kernel = \pocketmine\bootstrap();
$world = $kernel->getWorld();
$worldConfig = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\WorldConfig::class);
if ($worldConfig instanceof \pocketmine\core\resource\WorldConfig) {
    $worldConfig->spawnMobs = false; // no mobs interfering with the pickup assertions
}

function pickupPlayer(World $world, float $x, float $z): EntityRef {
    return $world->spawn(
        (new EntityBuilder())
            ->at($x, 65, $z)
            ->with(new VelocityComponent())
            ->with(new HealthComponent(20, 20))
            ->with(new MetadataComponent(['username' => 'Picker']))
            ->with(new InventoryComponent(36))
            ->withTag(PlayerTag::class)
    );
}

/** Total count of a given item id across the inventory. */
function countItems(InventoryComponent $inv, int $itemId): int {
    $total = 0;
    foreach ($inv->getContents() as $stack) {
        if ($stack instanceof ItemStack && $stack->itemId === $itemId) {
            $total += $stack->count;
        }
    }
    return $total;
}

test('a fresh drop stays on the ground during the pickup delay, then walk-over collects it', function () use ($world, $kernel): void {
    $player = pickupPlayer($world, 400, 400);
    $item = $kernel->getEntitySpawnService()->spawnItem(401, 65, 400, new ItemStack(5, 0, 3)); // 3 planks, 1 block away
    $itemId = $item->getId();

    // 9 ticks: the 10-tick pickup delay has not elapsed yet.
    for ($i = 0; $i < 9; $i++) {
        $world->tick(0.05);
    }
    ok($world->getEntity($itemId) !== null, 'item still on the ground during the pickup delay');

    // 5 more ticks: delay elapsed, player within 1.5 blocks -> collected.
    for ($i = 0; $i < 5; $i++) {
        $world->tick(0.05);
    }
    ok($world->getEntity($itemId) === null, 'item entity despawned after walk-over pickup');
    $inv = $player->getEntity()?->get(InventoryComponent::class);
    ok($inv !== null && countItems($inv, 5) === 3, 'picked-up stack landed in the inventory');

    $world->despawn($player->getEntity());
    $world->tick(0.05);
});

test('picked-up items stack onto an existing stack', function () use ($world, $kernel): void {
    $player = pickupPlayer($world, 410, 400);
    $inv = $player->getEntity()?->get(InventoryComponent::class);
    $inv?->set(0, new ItemStack(5, 0, 60)); // 60 planks: room for 4 more in this stack

    $item = $kernel->getEntitySpawnService()->spawnItem(411, 65, 400, new ItemStack(5, 0, 2));
    $itemId = $item->getId();
    for ($i = 0; $i < 15; $i++) {
        $world->tick(0.05);
    }
    ok($world->getEntity($itemId) === null, 'item entity despawned after stacking pickup');
    $stack0 = $inv?->get(0);
    ok($stack0 !== null && $stack0->count === 62, 'picked-up items merged into the existing stack');

    $world->despawn($player->getEntity());
    $world->tick(0.05);
});

test('a full inventory leaves the item on the ground', function () use ($world, $kernel): void {
    $player = pickupPlayer($world, 420, 400);
    $inv = $player->getEntity()?->get(InventoryComponent::class);
    if ($inv !== null) {
        for ($i = 0; $i < $inv->size; $i++) {
            $inv->set($i, new ItemStack(5, 0, 64)); // full stacks, no room at all
        }
    }

    $item = $kernel->getEntitySpawnService()->spawnItem(421, 65, 400, new ItemStack(4, 0, 1)); // 1 cobblestone
    $itemId = $item->getId();
    for ($i = 0; $i < 15; $i++) {
        $world->tick(0.05);
    }
    ok($world->getEntity($itemId) !== null, 'item stays on the ground when the inventory is full');
    $stack0 = $inv?->get(0);
    ok($stack0 !== null && $stack0->count === 64, 'existing stacks untouched by a rejected pickup');

    $world->despawn($player->getEntity());
    if ($world->getEntity($itemId) !== null) {
        $world->despawn($world->getEntity($itemId));
    }
    $world->tick(0.05);
});

test('right-clicking a dropped item collects it (interact dispatch)', function () use ($world, $kernel): void {
    $player = pickupPlayer($world, 430, 400);
    // 2.5 blocks away: inside the right-click reach (3.0) but outside the
    // walk-over radius (1.5), so the walk-over system cannot pre-empt the
    // interact() route this test exercises.
    $item = $kernel->getEntitySpawnService()->spawnItem(432.5, 65, 400, new ItemStack(5, 0, 1));
    $itemId = $item->getId();

    // Wait out the pickup delay (the entity stays put meanwhile).
    for ($i = 0; $i < 12; $i++) {
        $world->tick(0.05);
    }
    ok($world->getEntity($itemId) !== null, 'item present before the right-click');

    $interaction = $kernel->getEntityInteractionService();
    same(true, $interaction->interact($player, $item), 'right-click interact() collects the item');
    $world->tick(0.05);
    ok($world->getEntity($itemId) === null, 'item entity removed after right-click pickup');
    $inv = $player->getEntity()?->get(InventoryComponent::class);
    ok($inv !== null && countItems($inv, 5) === 1, 'right-clicked item landed in the inventory');

    $world->despawn($player->getEntity());
    $world->tick(0.05);
});

exit(runTests());

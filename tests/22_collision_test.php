<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\component\CollisionComponent;
use pocketmine\core\component\ItemStack;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;
use pocketmine\core\resource\BlockRegistry;
use pocketmine\core\resource\ChunkStore;

/**
 * Block collision (BlockCollisionSystem).
 *
 * Non-player entities (mobs, dropped items) must not pass through blocks:
 * the post-movement collision pass clamps the pending position against solid
 * blocks (axis-separated, so entities slide along walls), lands falling
 * entities on the ground, and excludes players (client-authoritative).
 *
 * The test region (chunk 0,0) is cleared and rebuilt deterministically:
 * a stone floor at y=63 and a stone wall at x=12 (y 64-66, z 9-16).
 */

$kernel = \pocketmine\bootstrap();
$world = $kernel->getWorld();
$worldConfig = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\WorldConfig::class);
if ($worldConfig instanceof \pocketmine\core\resource\WorldConfig) {
    $worldConfig->spawnMobs = false; // no mob spawning interfering with the assertions
}

/** Load chunk 0,0 and carve the deterministic test region. */
function buildCollisionRegion(World $world): void {
    $kernel = \pocketmine\Kernel::getInstance();
    $kernel?->getChunkLoadService()->loadChunk(0, 0);

    $store = $world->getResourceRegistry()->get(ChunkStore::class);
    if (!$store instanceof ChunkStore) {
        ok(false, 'ChunkStore resource available');
        return;
    }
    // Clear the whole region, then lay the floor and the wall.
    for ($x = 7; $x <= 15; $x++) {
        for ($z = 8; $z <= 17; $z++) {
            for ($y = 62; $y <= 70; $y++) {
                $store->setBlock($x, $y, $z, 0);
            }
            $store->setBlock($x, 63, $z, 1); // stone floor, top face at y=64
        }
    }
    for ($z = 9; $z <= 16; $z++) {
        for ($y = 64; $y <= 66; $y++) {
            $store->setBlock(12, $y, $z, 1); // stone wall at x=12
        }
    }
}

/** A plain collidable dummy (no AI) - the exact target of BlockCollisionSystem. */
function collisionDummy(World $world, float $x, float $y, float $z, float $vx, float $vy, float $vz): EntityRef {
    return $world->spawn(
        (new EntityBuilder())
            ->at($x, $y, $z)
            ->with(new VelocityComponent($vx, $vy, $vz))
            ->with(new CollisionComponent())
    );
}

buildCollisionRegion($world);

test('a moving entity is stopped by a wall (does not pass through blocks)', function () use ($world): void {
    $dummy = collisionDummy($world, 10, 64.5, 10, 4, 0, 0); // 4 blocks/s into the wall at x=12
    $id = $dummy->getId();

    for ($i = 0; $i < 30; $i++) {
        $world->tick(0.05);
    }

    $pos = $world->getEntity($id)?->get(PositionComponent::class);
    ok($pos !== null, 'dummy entity still exists');
    if ($pos === null) {
        return;
    }
    // Half-width 0.3: the box max edge must stay short of the wall face at 12.
    ok($pos->x < 11.72, 'entity stopped before the wall (x=' . round($pos->x, 3) . ')');
    ok($pos->x + 0.3 < 12.0, 'entity bounding box does not overlap the wall');
    ok($pos->y > 63.9 && $pos->y < 66.0, 'entity is standing on the floor (y=' . round($pos->y, 3) . ')');
});

test('a blocked entity slides along the wall instead of sticking', function () use ($world): void {
    $dummy = collisionDummy($world, 10, 64.5, 9.5, 4, 0, 3); // diagonal into the wall
    $id = $dummy->getId();

    for ($i = 0; $i < 15; $i++) {
        $world->tick(0.05);
    }

    $pos = $world->getEntity($id)?->get(PositionComponent::class);
    ok($pos !== null, 'dummy entity still exists');
    if ($pos === null) {
        return;
    }
    ok($pos->x < 11.9, 'X axis blocked at the wall (x=' . round($pos->x, 3) . ')');
    ok($pos->z > 11.5, 'Z axis kept moving - entity slid along the wall (z=' . round($pos->z, 3) . ')');
});

test('a dropped item falls and lands on the ground instead of sinking through', function () use ($world, $kernel): void {
    $item = $kernel->getEntitySpawnService()->spawnItem(10, 66, 10, new ItemStack(5, 0, 1));
    $id = $item->getId();
    // Kill the spawn throw so the drop simply falls from 2 blocks up.
    $itemVel = $world->getEntity($id)?->get(VelocityComponent::class);
    if ($itemVel !== null) {
        $itemVel->y = 0.0;
    }

    $minY = 66.0;
    for ($i = 0; $i < 60; $i++) {
        $world->tick(0.05);
        $y = $world->getEntity($id)?->get(PositionComponent::class)?->y ?? 66.0;
        $minY = min($minY, $y);
    }
    $pos = $world->getEntity($id)?->get(PositionComponent::class);
    ok($pos !== null, 'item entity still exists after landing');
    if ($pos === null) {
        return;
    }
    ok($minY > 63.5, 'item never sank below the floor (min y=' . round($minY, 3) . ')');
    ok($pos->y > 63.9 && $pos->y < 65.0, 'item rests on the ground (y=' . round($pos->y, 3) . ')');

    // 20 more ticks: the item must stay put, not keep sinking.
    for ($i = 0; $i < 20; $i++) {
        $world->tick(0.05);
    }
    $yAfter = $world->getEntity($id)?->get(PositionComponent::class)?->y;
    ok($yAfter !== null && $yAfter > 63.9, 'item stays on the ground after settling (y=' . round((float)$yAfter, 3) . ')');
});

test('an entity falling through empty air is not frozen by collision', function () use ($world): void {
    $dummy = collisionDummy($world, 10, 120, 10, 0, -2, 0); // no blocks at y=120
    $id = $dummy->getId();

    for ($i = 0; $i < 6; $i++) {
        $world->tick(0.05);
    }

    $pos = $world->getEntity($id)?->get(PositionComponent::class);
    ok($pos !== null, 'dummy entity still exists');
    if ($pos !== null) {
        ok($pos->y < 119.9, 'entity fell freely through air (y=' . round($pos->y, 3) . ')');
    }
});

test('players are excluded from server-side collision (client-authoritative)', function () use ($world): void {
    // A player entity INSIDE the wall block, with a collision box: neither
    // collision nor MovementSystem may touch its position - the client owns
    // player movement entirely (server-side velocity is ignored for players,
    // matching the PhysicsSystem exclusion).
    $player = $world->spawn(
        (new EntityBuilder())
            ->at(12, 65, 10)
            ->with(new VelocityComponent(4, 0, 0))
            ->with(new CollisionComponent())
            ->withTag(PlayerTag::class)
    );
    $id = $player->getId();

    for ($i = 0; $i < 5; $i++) {
        $world->tick(0.05);
    }

    $pos = $world->getEntity($id)?->get(PositionComponent::class);
    ok($pos !== null, 'player entity still exists');
    if ($pos !== null) {
        ok(abs($pos->x - 12.0) < 0.0001, 'player position untouched by server physics (x=' . round($pos->x, 3) . ')');
    }
});

exit(runTests());

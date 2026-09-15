<?php

declare(strict_types=1);

/**
 * Mob AI obstacle navigation (14.31): a mob walking into a 1-block step jumps
 * onto it, and a mob pushing into a 2-block wall strafes around it instead of
 * grinding into the wall forever. Uses the real AISystem steering via
 * spawnMob + run() against hand-built terrain in the default world (world 0:
 * PhysicsSystem/BlockCollisionSystem/AI probes read the global ChunkStore
 * resource, so isolated world bundles would leave the collision layer blind).
 * Coordinates are offset to (1100, 10) to stay clear of spawn activity.
 */

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\component\AIStateComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\constants\BlockIds;
use pocketmine\core\enum\EntityType;
use pocketmine\core\resource\BlockRegistry;
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\system\AISystem;
use pocketmine\core\system\BlockCollisionSystem;
use pocketmine\core\system\PhysicsSystem;

$kernel = \pocketmine\bootstrap();
$world = $kernel->getWorld();
$store = $world->getResourceRegistry()->get(ChunkStore::class);
ok($store instanceof ChunkStore, 'global chunk store');
$blocks = $world->getResourceRegistry()->get(BlockRegistry::class);
ok($blocks instanceof BlockRegistry, 'block registry');

// --- Build an isolated corridor far from spawn (world 0) -------------------
const OX = 1100; // origin X
const OZ = 10;   // origin Z
const FLOOR_Y = 40;

// Load the two chunks the corridor spans (1100..1131 → chunks 68, 69).
foreach ([[68, 0], [69, 0]] as [$cx, $cz]) {
    $kernel->getChunkLoadService()->loadChunk($cx, $cz);
}

// Flat stone floor at FLOOR_Y (x OX..OX+31, z OZ-5..OZ+5) with boundary walls.
for ($x = OX; $x <= OX + 31; $x++) {
    for ($z = OZ - 5; $z <= OZ + 5; $z++) {
        $store->setBlock($x, FLOOR_Y, $z, BlockIds::STONE);
    }
    foreach ([OZ - 5, OZ + 5] as $z) {
        $store->setBlock($x, FLOOR_Y + 1, $z, BlockIds::STONE);
        $store->setBlock($x, FLOOR_Y + 2, $z, BlockIds::STONE);
    }
}

$ai = new AISystem();
$physics = new PhysicsSystem();
$collision = new BlockCollisionSystem();

/** Spawn a zombie and give it a chase target (fake player) at ($tx, $tz). */
function spawnChaser(\pocketmine\Kernel $kernel, \pocketmine\core\ecs\World $world, float $x, float $z, float $tx, float $tz): \pocketmine\core\ecs\Entity {
    $ref = $kernel->getEntitySpawnService()->spawnMob(EntityType::Zombie, $x, FLOOR_Y + 1.0, $z, 0);
    $mob = $ref->getEntity();
    ok($mob !== null, 'mob spawned');
    $mob->set(AIStateComponent::class, new AIStateComponent());
    // A fake player target (targetable) standing on the corridor floor.
    $target = $world->spawn(
        (new \pocketmine\core\ecs\EntityBuilder())
            ->with(new PositionComponent($tx, FLOOR_Y + 1.0, $tz))
            ->with(new \pocketmine\core\component\RotationComponent())
            ->with(new VelocityComponent())
            ->with(new \pocketmine\core\component\HealthComponent(20, 20))
            ->with(new \pocketmine\core\component\MetadataComponent())
            ->with(new \pocketmine\core\component\WorldComponent(0))
            ->with(new \pocketmine\core\component\tags\PlayerTag()),
    );
    $mob->get(AIStateComponent::class)->setTargetEntity($target->getId());
    return $mob;
}

/** One AI+physics+collision tick. */
function stepTick(AISystem $ai, PhysicsSystem $physics, BlockCollisionSystem $collision, \pocketmine\core\ecs\World $world): void {
    $ai->run($world, 1 / 20);
    foreach ($physics->getTargetArchetypes($world) as $archetype) {
        $physics->runParallel($archetype, 1 / 20);
    }
    $collision->run($world, 1 / 20);
    $world->applyPendingComponents();
}

test('mob jumps a 1-block step while chasing', function () use ($kernel, $world, $store, $ai, $physics, $collision): void {
    // 1-block step at x=OX+12 across the full corridor width: the only way east.
    for ($z = OZ - 5; $z <= OZ + 5; $z++) {
        $store->setBlock(OX + 12, FLOOR_Y + 1, $z, BlockIds::STONE);
    }
    $mob = spawnChaser($kernel, $world, OX + 8.5, OZ + 0.5, OX + 16.5, OZ + 0.5);

    $jumped = false;
    $topped = false;
    for ($i = 0; $i < 120; $i++) {
        stepTick($ai, $physics, $collision, $world);
        $pos = $mob->get(PositionComponent::class);
        if ($pos === null) {
            continue;
        }
        if ((float)$mob->get(VelocityComponent::class)->y > 0.5) {
            $jumped = true;
        }
        if ($pos->x > OX + 12.5) {
            $topped = true;
            break;
        }
    }
    $x = (int)floor($mob->get(PositionComponent::class)->x) - OX;
    ok($topped, "mob crossed the step (now at dx=$x)");
    ok($jumped, 'mob jumped to cross');
});

test('mob strafes around a 2-block wall instead of grinding', function () use ($kernel, $world, $store, $ai, $physics, $collision): void {
    // 2-block wall at x=OX+20 (y FLOOR_Y+1..+2), so the step-up cannot clear it.
    for ($z = OZ - 5; $z <= OZ + 5; $z++) {
        $store->setBlock(OX + 20, FLOOR_Y + 1, $z, BlockIds::STONE);
        $store->setBlock(OX + 20, FLOOR_Y + 2, $z, BlockIds::STONE);
    }
    $mob = spawnChaser($kernel, $world, OX + 16.5, OZ + 0.5, OX + 24.5, OZ + 0.5);

    $passed = false;
    for ($i = 0; $i < 300; $i++) {
        stepTick($ai, $physics, $collision, $world);
        $pos = $mob->get(PositionComponent::class);
        if ($pos !== null && $pos->x > OX + 20.6) {
            $passed = true;
            break;
        }
    }
    $final = $mob->get(PositionComponent::class);
    ok($passed, sprintf('mob got past the wall (dx=%.2f dz=%.2f)', $final->x - OX, $final->z - OZ));
});

exit(runTests());

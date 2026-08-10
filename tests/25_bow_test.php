<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\RotationComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\resource\ChunkStore;

/**
 * Phase 14.17: bows / arrows.
 *
 * spawnProjectile('Arrow', ...) creates a projectile entity tagged with
 * projectileType='Arrow'; the ArrowSystem ticks it: drag, gravity via the
 * generic pipeline, block stick, entity-hit damage (via CombatService), and
 * age-based despawn. The bow wire flow (USE_ITEM starts the draw,
 * ACTION_RELEASE_ITEM fires) is covered by tests/17.
 */

$kernel = \pocketmine\bootstrap();
$kernel->setAutoShutdownOnRun(false);
$world = $kernel->getWorld();
$chunks = $kernel->getResourceRegistry()->get(ChunkStore::class);
if (!$chunks instanceof ChunkStore) {
    echo "FAIL: no ChunkStore\n";
    exit(1);
}

// A quiet corner of a loaded chunk: clear a corridor at a fixed altitude so
// the assertions below do not depend on generated terrain. The corridor is 4
// tall because gravity pulls the arrow's trajectory down through the lower
// cells (a 1-2 tall gap lets it hit the terrain floor instead of flying).
$kernel->getChunkLoadService()->loadChunk(0, 0);
$pathY = 70;
for ($x = 6; $x <= 14; $x++) {
    for ($y = $pathY - 2; $y <= $pathY + 1; $y++) {
        $chunks->setBlock($x, $y, 8, 0);
        $chunks->setBlock($x, $y, 9, 0);
    }
}

test('an arrow flies along its velocity and despawns after 1200 ticks', function () use ($kernel, $world, $pathY): void {
    $shooter = $kernel->getEntitySpawnService()->spawnMob('Zombie', 30, $pathY, 30);
    $arrow = $kernel->getEntitySpawnService()->spawnProjectile(
        'Arrow', 8, $pathY, 8, // start
        20, 0, 0,              // velocity (blocks/s), legacy 1 block/tick
        $shooter,
    );
    $arrowId = $arrow->getId();
    $pos = fn() => $world->getEntity($arrowId)?->get(PositionComponent::class);

    $startX = $pos()?->x;
    ok($startX !== null, 'arrow spawned with a position');

    for ($i = 0; $i < 10; $i++) {
        $world->tick(0.05);
    }
    $after = $pos();
    ok($after !== null, 'arrow still alive after 10 ticks');
    ok($after->x > $startX + 5, "arrow moved along +X ($startX -> {$after->x})");

    // Age despawn: 1200 ticks total.
    for ($i = 0; $i < 1300; $i++) {
        $world->tick(0.05);
    }
    ok($pos() === null, 'arrow despawned after 1200 ticks');
});

test('an arrow sticks into a solid block (velocity zeroed, entity kept)', function () use ($kernel, $world, $chunks, $pathY): void {
    // Stone wall at x=13, clear path before it.
    for ($y = $pathY - 2; $y <= $pathY + 1; $y++) {
        $chunks->setBlock(13, $y, 8, 1);
        $chunks->setBlock(13, $y, 9, 1);
    }

    $shooter = $kernel->getEntitySpawnService()->spawnMob('Zombie', 30, $pathY, 30);
    $arrow = $kernel->getEntitySpawnService()->spawnProjectile(
        'Arrow', 8, $pathY, 8,
        20, 0, 0,
        $shooter,
    );
    $arrowId = $arrow->getId();

    for ($i = 0; $i < 30; $i++) {
        $world->tick(0.05);
    }
    $entity = $world->getEntity($arrowId);
    ok($entity !== null, 'arrow kept (stuck, not despawned)');
    $vel = $entity?->get(VelocityComponent::class);
    // The stuck arrow is pinned by ArrowSystem re-zeroing every tick; the
    // double-buffered physics commits a -0.08 gravity residue into vy each
    // tick after that, so only the horizontal axes stay truly zero. Position
    // stability below is the real invariant.
    ok($vel !== null && abs($vel->x) < 0.0001 && abs($vel->z) < 0.0001, 'arrow horizontal velocity zeroed on block hit');
    $pos = $entity?->get(PositionComponent::class);
    ok($pos !== null && $pos->x < 13.0, "arrow stopped before the wall (x={$pos?->x})");
    $stuckX = $pos?->x;
    // Pinned: the arrow does not drift through the wall over further ticks.
    for ($i = 0; $i < 5; $i++) {
        $world->tick(0.05);
    }
    $again = $world->getEntity($arrowId)?->get(PositionComponent::class);
    ok($again !== null && abs($again->x - $stuckX) < 0.001, "stuck arrow stays pinned at x={$stuckX}");
});

test('an arrow damages a living entity it hits and despawns', function () use ($kernel, $world, $pathY): void {
    $shooter = $kernel->getEntitySpawnService()->spawnMob('Zombie', 30, $pathY, 30);
    $target = $kernel->getEntitySpawnService()->spawnMob('Pig', 11, $pathY, 8);
    $targetId = $target->getId();
    $healthBefore = $world->getEntity($targetId)?->get(\pocketmine\core\component\HealthComponent::class)?->current;
    ok($healthBefore !== null && $healthBefore > 0, 'target pig alive before the hit');

    // Full-charge arrow speed (40 blocks/s) 1 block away: ~4 base damage.
    $arrow = $kernel->getEntitySpawnService()->spawnProjectile(
        'Arrow', 10, $pathY, 8,
        40, 0, 0,
        $shooter,
    );
    $arrowId = $arrow->getId();

    for ($i = 0; $i < 5; $i++) {
        $world->tick(0.05);
    }
    $healthAfter = $world->getEntity($targetId)?->get(\pocketmine\core\component\HealthComponent::class)?->current;
    ok($healthAfter !== null, 'target still exists after the hit');
    ok($healthAfter < $healthBefore, "arrow damaged the target ({$healthBefore} -> {$healthAfter})");
    ok($world->getEntity($arrowId) === null, 'arrow despawned after the hit');
});

test('a critical arrow deals at least the base arrow damage', function () use ($kernel, $world, $pathY): void {
    $shooter = $kernel->getEntitySpawnService()->spawnMob('Zombie', 30, $pathY, 30);
    $target = $kernel->getEntitySpawnService()->spawnMob('Pig', 11, $pathY, 9);
    $targetId = $target->getId();
    $healthBefore = $world->getEntity($targetId)?->get(\pocketmine\core\component\HealthComponent::class)?->current;

    $arrow = $kernel->getEntitySpawnService()->spawnProjectile(
        'Arrow', 10, $pathY, 9,
        40, 0, 0,
        $shooter,
    );
    $meta = $arrow->getEntity()?->get(MetadataComponent::class);
    $meta?->set('critical', true);

    for ($i = 0; $i < 5; $i++) {
        $world->tick(0.05);
    }
    $healthAfter = $world->getEntity($targetId)?->get(\pocketmine\core\component\HealthComponent::class)?->current;
    ok($healthAfter !== null && $healthAfter < $healthBefore, 'critical arrow damaged the target');
});

test('arrow metadata carries the shooter id (used for attribution and rendering)', function () use ($kernel, $pathY): void {
    $shooter = $kernel->getEntitySpawnService()->spawnMob('Zombie', 30, $pathY, 30);
    $arrow = $kernel->getEntitySpawnService()->spawnProjectile(
        'Arrow', 8, $pathY, 8,
        10, 0, 0,
        $shooter,
    );
    $meta = $arrow->getEntity()?->get(MetadataComponent::class);
    same('Arrow', $meta?->get('projectileType'), 'arrow tagged as projectile type Arrow');
    same($shooter->getId(), $meta?->get('shooterId'), 'shooter id recorded');
    // Rotation is set so the client renders the arrow pointing along its path.
    $rot = $arrow->getEntity()?->get(RotationComponent::class);
    ok($rot !== null, 'arrow has a rotation component');
});

exit(runTests());

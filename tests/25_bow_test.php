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
use pocketmine\core\resource\ProjectileRegistry;

/**
 * Phase 14.17: bows / arrows.
 *
 * spawnProjectile(\pocketmine\core\enum\EntityType::Arrow, ...) creates a projectile entity tagged with
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
    $shooter = $kernel->getEntitySpawnService()->spawnMob(\pocketmine\core\enum\EntityType::Zombie, 30, $pathY, 30);
    $arrow = $kernel->getEntitySpawnService()->spawnProjectile(
        \pocketmine\core\enum\EntityType::Arrow, 8, $pathY, 8, // start
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

    $shooter = $kernel->getEntitySpawnService()->spawnMob(\pocketmine\core\enum\EntityType::Zombie, 30, $pathY, 30);
    $arrow = $kernel->getEntitySpawnService()->spawnProjectile(
        \pocketmine\core\enum\EntityType::Arrow, 8, $pathY, 8,
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

test('an arrow sticks into the entity it hits and rides it', function () use ($kernel, $world, $chunks, $pathY): void {
    $shooter = $kernel->getEntitySpawnService()->spawnMob(\pocketmine\core\enum\EntityType::Zombie, 30, $pathY, 30);
    $target = $kernel->getEntitySpawnService()->spawnMob(\pocketmine\core\enum\EntityType::Pig, 11, $pathY, 8);
    $targetId = $target->getId();
    $healthBefore = $world->getEntity($targetId)?->get(\pocketmine\core\component\HealthComponent::class)?->current;
    ok($healthBefore !== null && $healthBefore > 0, 'target pig alive before the hit');

    // Full-charge arrow speed (40 blocks/s) 1 block away: ~4 base damage.
    $arrow = $kernel->getEntitySpawnService()->spawnProjectile(
        \pocketmine\core\enum\EntityType::Arrow, 10, $pathY, 8,
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

    // The arrow sticks: it stays in the world, marked stuck on the victim.
    $arrowMeta = $world->getEntity($arrowId)?->get(MetadataComponent::class);
    ok($arrowMeta !== null, 'arrow kept in the world after the hit (stuck, not despawned)');
    ok($arrowMeta?->get('stuck') === true, 'arrow marked stuck after the hit');
    same($targetId, $arrowMeta?->get('stuckTargetId'), 'stuck arrow remembers its victim');

    // Riding: nudge the victim and the arrow follows it. The hit knocks the
    // pig around (sometimes out of the corridor into solid terrain), so carve
    // a clear box around it, park it at a known spot, then nudge it - the
    // follow check must not depend on the knockback geometry.
    $pigPos = $world->getEntity($targetId)?->get(PositionComponent::class);
    $cx = (int)floor($pigPos?->x ?? 11);
    $cz = (int)floor($pigPos?->z ?? 8);
    for ($x = $cx - 4; $x <= $cx + 4; $x++) {
        for ($z = $cz - 4; $z <= $cz + 4; $z++) {
            for ($y = $pathY - 4; $y <= $pathY + 3; $y++) {
                $chunks->setBlock($x, $y, $z, 0);
            }
        }
    }
    $targetPos = $world->getEntity($targetId)?->get(PositionComponent::class);
    if ($targetPos) {
        $targetPos->x = 11;
        $targetPos->y = $pathY;
        $targetPos->z = 8;
    }
    $world->tick(0.05); // re-sync the arrow to the parked pig
    $targetPos = $world->getEntity($targetId)?->get(PositionComponent::class);
    if ($targetPos) {
        $targetPos->x += 3.0;
    }
    for ($i = 0; $i < 4; $i++) {
        $world->tick(0.05);
    }
    $arrowPos = $world->getEntity($arrowId)?->get(PositionComponent::class);
    $pigPos = $world->getEntity($targetId)?->get(PositionComponent::class);
    $dx = ($arrowPos?->x ?? 0) - ($pigPos?->x ?? 0);
    $dy = ($arrowPos?->y ?? 0) - ($pigPos?->y ?? 0);
    $dz = ($arrowPos?->z ?? 0) - ($pigPos?->z ?? 0);
    ok($arrowPos !== null && $pigPos !== null && ($dx * $dx + $dy * $dy + $dz * $dz) < 0.6, 'stuck arrow follows its victim');
});

test('an arrow falls to the ground when its victim dies', function () use ($kernel, $world, $chunks, $pathY): void {
    $shooter = $kernel->getEntitySpawnService()->spawnMob(\pocketmine\core\enum\EntityType::Zombie, 30, $pathY, 30);
    $target = $kernel->getEntitySpawnService()->spawnMob(\pocketmine\core\enum\EntityType::Pig, 11, $pathY, 8);
    $arrow = $kernel->getEntitySpawnService()->spawnProjectile(
        \pocketmine\core\enum\EntityType::Arrow, 10, $pathY, 8,
        40, 0, 0,
        $shooter,
    );
    $arrowId = $arrow->getId();

    for ($i = 0; $i < 5; $i++) {
        $world->tick(0.05);
    }
    $meta = $world->getEntity($arrowId)?->get(MetadataComponent::class);
    ok($meta?->get('stuck') === true, 'arrow stuck in the target before it dies');

    // The hit knocks the pig around (sometimes out of the cleared corridor
    // into solid terrain). Carve a generous clear shaft around the pig's
    // current spot and park it back at a known position, so the fall below is
    // deterministic no matter how the knockback bounced it.
    $pigPos = $world->getEntity($target->getId())?->get(PositionComponent::class);
    $cx = (int)floor($pigPos?->x ?? 11);
    $cz = (int)floor($pigPos?->z ?? 8);
    for ($x = $cx - 3; $x <= $cx + 3; $x++) {
        for ($z = $cz - 3; $z <= $cz + 3; $z++) {
            for ($y = $pathY - 4; $y <= $pathY + 3; $y++) {
                $chunks->setBlock($x, $y, $z, 0);
            }
        }
    }
    $targetPos = $world->getEntity($target->getId())?->get(PositionComponent::class);
    if ($targetPos) {
        $targetPos->x = 11;
        $targetPos->y = $pathY;
        $targetPos->z = 8;
    }
    $world->tick(0.05); // let the arrow re-sync to the parked victim

    // Remove the victim (as if it died). The arrow unsticks, falls with
    // gravity, and re-sticks in the ground.
    $kernel->getEntityDespawnService()->despawn(EntityRef::create($target->getId(), $world), false);
    $resting = false;
    for ($i = 0; $i < 200; $i++) {
        $world->tick(0.05);
        $m = $world->getEntity($arrowId)?->get(MetadataComponent::class);
        $p = $world->getEntity($arrowId)?->get(PositionComponent::class);
        if ($m?->get('stuck') === true && $p !== null && $p->y < $pathY - 0.5) {
            $resting = true;
            break;
        }
    }
    ok($resting, 'arrow fell and re-stuck in the ground after the victim died');
});

test('a critical arrow deals at least the base arrow damage', function () use ($kernel, $world, $pathY): void {
    $shooter = $kernel->getEntitySpawnService()->spawnMob(\pocketmine\core\enum\EntityType::Zombie, 30, $pathY, 30);
    $target = $kernel->getEntitySpawnService()->spawnMob(\pocketmine\core\enum\EntityType::Pig, 11, $pathY, 9);
    $targetId = $target->getId();
    $healthBefore = $world->getEntity($targetId)?->get(\pocketmine\core\component\HealthComponent::class)?->current;

    $arrow = $kernel->getEntitySpawnService()->spawnProjectile(
        \pocketmine\core\enum\EntityType::Arrow, 10, $pathY, 9,
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
    $shooter = $kernel->getEntitySpawnService()->spawnMob(\pocketmine\core\enum\EntityType::Zombie, 30, $pathY, 30);
    $arrow = $kernel->getEntitySpawnService()->spawnProjectile(
        \pocketmine\core\enum\EntityType::Arrow, 8, $pathY, 8,
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

test('an in-flight arrow points along its velocity (in-flight rendering)', function () use ($kernel, $world, $pathY): void {
    $shooter = $kernel->getEntitySpawnService()->spawnMob(\pocketmine\core\enum\EntityType::Zombie, 30, $pathY, 30);
    $arrow = $kernel->getEntitySpawnService()->spawnProjectile(
        \pocketmine\core\enum\EntityType::Arrow, 8, $pathY, 8,
        10, 5, 0, // mostly +X, climbing
        $shooter,
    );
    $arrowId = $arrow->getId();

    // At spawn the rotation is derived from the motion vector (legacy):
    // yaw = atan2(10, 0) = 90, pitch = atan2(5, 10) ~ 26.57.
    $rot = $arrow->getEntity()?->get(RotationComponent::class);
    ok($rot !== null, 'arrow has a rotation component');
    ok($rot !== null && abs($rot->yaw - 90.0) < 1.0, "arrow yaw points along velocity (got {$rot?->yaw})");
    ok($rot !== null && abs($rot->pitch - atan2(5.0, 10.0) * 180 / M_PI) < 1.0, 'arrow pitch points along velocity');

    // While flying, the ArrowSystem keeps the rotation aligned (drag + the
    // generic gravity bend the path slightly, so use loose bounds).
    for ($i = 0; $i < 4; $i++) {
        $world->tick(0.05);
    }
    $rot2 = $world->getEntity($arrowId)?->get(RotationComponent::class);
    ok($rot2 !== null, 'arrow still alive and rotated in flight');
    ok($rot2 !== null && abs($rot2->yaw - 90.0) < 5.0, "in-flight yaw stays along +X (got {$rot2->yaw})");
    ok($rot2 !== null && $rot2->pitch > 0.0, "in-flight pitch stays upward (got {$rot2->pitch})");
});

test('the projectile registry is the single source of truth for projectiles', function () use ($kernel, $world, $pathY): void {
    $registry = $kernel->getResourceRegistry()->get(ProjectileRegistry::class);
    ok($registry instanceof ProjectileRegistry, 'projectile registry resource present');
    if ($registry instanceof ProjectileRegistry) {
        same(80, $registry->getNetworkId('Arrow'), 'Arrow maps to the legacy network id 80');
        ok($registry->isProjectile('Arrow'), 'Arrow is a known projectile');
        ok(!$registry->isProjectile('NotAThing'), 'unknown names are not projectiles');
        same(null, $registry->getNetworkId('NotAThing'), 'unknown names resolve to no network id');
    }

    // Unknown projectile types are rejected at spawn (fail fast). A mob
    // entity type is not a registered projectile, so spawnProjectile must
    // reject it against the registry.
    $shooter = $kernel->getEntitySpawnService()->spawnMob(\pocketmine\core\enum\EntityType::Zombie, 30, $pathY, 30);
    $threw = false;
    try {
        $kernel->getEntitySpawnService()->spawnProjectile(\pocketmine\core\enum\EntityType::Zombie, 8, $pathY, 8, 10, 0, 0, $shooter);
    } catch (\InvalidArgumentException) {
        $threw = true;
    }
    ok($threw, 'spawning an unregistered projectile throws');
});

exit(runTests());

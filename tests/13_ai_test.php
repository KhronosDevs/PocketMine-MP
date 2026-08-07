<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\api\event\EntityDamageEvent;
use pocketmine\core\component\AIStateComponent;
use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\component\tags\MonsterTag;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;

/**
 * Phase 12.1: real AI.
 *
 * AISystem is now a sequential main-thread system: it maintains the SpatialIndex,
 * acquires the nearest living player as a target for hostile mobs, chases and
 * attacks through the 12.2 combat pipeline (damage event + cooldowns), and flees
 * when hurt. Mob stats (health/damage/speed/ranges) come from EntitySpawnService.
 */

$kernel = \pocketmine\bootstrap();
$world = $kernel->getWorld();
$spawn = $kernel->getEntitySpawnService();

function spawnPlayer(World $world, float $x, float $z): EntityRef {
    return $world->spawn(
        (new EntityBuilder())
            ->at($x, 65, $z)
            ->with(new HealthComponent(20, 20))
            ->with(new MetadataComponent(['username' => 'Steve']))
            ->with(new InventoryComponent(36))
            ->withTag(PlayerTag::class)
    );
}

/** Run one full tick (AI -> movement -> physics -> pending swap). */
function tickWorld(World $world): void {
    $world->tick(0.05);
}

/** Distance in the XZ plane. */
function xzDist(PositionComponent $a, PositionComponent $b): float {
    $dx = $a->x - $b->x;
    $dz = $a->z - $b->z;
    return sqrt($dx * $dx + $dz * $dz);
}

test('hostile mob spawns with AI state and stats, no AI component for plain entities', function () use ($spawn, $world) {
    $zombie = $spawn->spawnMob('Zombie', 300, 65, 300);
    $ai = $zombie->getAIState();
    ok($ai instanceof AIStateComponent, 'zombie has AIStateComponent');
    same(20.0, $zombie->getEntity()?->get(HealthComponent::class)->max, 'zombie health from stat table');
    near(3.0, $ai->attackDamage, 1e-9, 'zombie attack damage');
    near(1.0, $ai->speedModifier, 1e-9, 'zombie speed');
    near(16.0, $ai->followRange, 1e-9, 'zombie follow range');
    ok($zombie->getEntity()?->has(MonsterTag::class), 'zombie has MonsterTag');

    $cow = $spawn->spawnMob('Cow', 310, 65, 300);
    $cowAi = $cow->getAIState();
    ok($cowAi instanceof AIStateComponent, 'cow has AIStateComponent');
    near(0.0, $cowAi->attackDamage, 1e-9, 'cow does not attack');
    near(0.3, $cowAi->retreatHealthPercent, 1e-9, 'cow retreats at 30% health');

    // A plain spawned entity (no type init) has no AI state.
    $plain = $world->spawn((new EntityBuilder())->at(320, 65, 300));
    ok(!$plain->getAIState() instanceof AIStateComponent, 'plain entity has no AI state');

    foreach ($world->getEntities() as $e) {
        $world->despawn($e);
    }
    tickWorld($world);
});

test('hostile mob acquires nearest player as target', function () use ($world, $spawn) {
    $player = spawnPlayer($world, 330, 300);
    $zombie = $spawn->spawnMob('Zombie', 335, 65, 300); // 5 blocks east

    // Zombie should acquire the player within a few ticks.
    $acquired = false;
    for ($i = 0; $i < 10; $i++) {
        tickWorld($world);
        $ai = $zombie->getAIState();
        if ($ai->targetEntity === $player->getId()) {
            $acquired = true;
            break;
        }
    }
    ok($acquired, 'zombie acquired the player as target');

    // A zombie far beyond follow range never acquires.
    $far = spawnPlayer($world, 400, 400);
    $farZombie = $spawn->spawnMob('Zombie', 500, 65, 400); // 100 blocks away
    for ($i = 0; $i < 10; $i++) {
        tickWorld($world);
    }
    ok($farZombie->getAIState()->targetEntity === null, 'far zombie has no target');

    foreach ($world->getEntities() as $e) {
        $world->despawn($e);
    }
    tickWorld($world);
});

test('zombie chases the player', function () use ($world, $spawn) {
    $player = spawnPlayer($world, 330, 320);
    $zombie = $spawn->spawnMob('Zombie', 345, 65, 320); // 15 blocks east

    $startPos = $zombie->getEntity()?->get(PositionComponent::class);
    $playerPos = $player->getEntity()?->get(PositionComponent::class);
    $startDist = xzDist($startPos, $playerPos);
    ok($startDist > 10, 'starts far away: ' . $startDist);

    $closest = $startDist;
    for ($i = 0; $i < 60; $i++) {
        tickWorld($world);
        $zPos = $zombie->getEntity()?->get(PositionComponent::class);
        $pPos = $player->getEntity()?->get(PositionComponent::class);
        if ($zPos && $pPos) {
            $closest = min($closest, xzDist($zPos, $pPos));
        }
    }
    ok($closest < $startDist - 2, 'zombie closed distance: from ' . round($startDist, 1) . ' to ' . round($closest, 1));

    foreach ($world->getEntities() as $e) {
        $world->despawn($e);
    }
    tickWorld($world);
});

test('zombie attacks through combat pipeline with cooldown', function () use ($world, $spawn, $kernel) {
    $player = spawnPlayer($world, 330, 340);
    $zombie = $spawn->spawnMob('Zombie', 331, 65, 340); // 1 block east -> in range

    $damageEvents = 0;
    $kernel->getEventPort()->subscribe(EntityDamageEvent::class, function (EntityDamageEvent $e) use (&$damageEvents) {
        if ($e->getCause() === EntityDamageEvent::CAUSE_ENTITY_ATTACK) {
            $damageEvents++;
        }
    });

    // Let the zombie acquire and attack over 5 seconds (100 ticks).
    for ($i = 0; $i < 100; $i++) {
        tickWorld($world);
    }

    $playerHealth = $player->getEntity()?->get(HealthComponent::class);
    ok($damageEvents > 0, 'damage event fired via combat pipeline: ' . $damageEvents);
    ok($playerHealth->current < 20, 'player took damage: ' . $playerHealth->current);

    // Cooldown (20 ticks) caps attacks: 100 ticks => at most ~5 attacks * 3 dmg.
    ok($damageEvents <= 6, 'attack cooldown respected (got ' . $damageEvents . ' attacks in 100 ticks)');
    ok($playerHealth->current >= 20 - 6 * 3.1, 'health consistent with capped damage: ' . $playerHealth->current);

    foreach ($world->getEntities() as $e) {
        $world->despawn($e);
    }
    tickWorld($world);
});

test('fatal AI damage kills the player through the combat pipeline', function () use ($world, $spawn, $kernel) {
    $player = spawnPlayer($world, 330, 360);
    $playerHealth = $player->getEntity()?->get(HealthComponent::class);
    $playerHealth->current = 2.0;
    $zombie = $spawn->spawnMob('Zombie', 331, 65, 360); // 1 block -> in range

    $deathEvents = 0;
    $kernel->getEventPort()->subscribe(\pocketmine\api\event\PlayerDeathEvent::class, function ($e) use (&$deathEvents) {
        $deathEvents++;
    });

    for ($i = 0; $i < 30; $i++) {
        tickWorld($world);
        if (!$player->isValid()) {
            break;
        }
    }
    ok(!$player->isValid(), 'player died from AI attack');
    ok($deathEvents >= 1, 'player death event fired: ' . $deathEvents);

    foreach ($world->getEntities() as $e) {
        $world->despawn($e);
    }
    tickWorld($world);
});

test('mob flees when hurt below retreat threshold, then stops when safe', function () use ($world, $spawn) {
    $player = spawnPlayer($world, 330, 380);
    $cow = $spawn->spawnMob('Cow', 332, 65, 380); // 2 blocks away, passive
    // Hurt the cow below its 30% retreat threshold (max 10 => <= 3).
    $cowHealth = $cow->getEntity()?->get(HealthComponent::class);
    $cowHealth->current = 2.0;

    $startPos = $cow->getEntity()?->get(PositionComponent::class);
    $playerPos = $player->getEntity()?->get(PositionComponent::class);
    $startDist = xzDist($startPos, $playerPos);

    $maxDist = $startDist;
    for ($i = 0; $i < 40; $i++) {
        tickWorld($world);
        $cPos = $cow->getEntity()?->get(PositionComponent::class);
        $pPos = $player->getEntity()?->get(PositionComponent::class);
        if ($cPos && $pPos) {
            $maxDist = max($maxDist, xzDist($cPos, $pPos));
        }
    }
    ok($maxDist > $startDist + 1, 'cow fled away from player: ' . round($startDist, 1) . ' -> ' . round($maxDist, 1));

    foreach ($world->getEntities() as $e) {
        $world->despawn($e);
    }
    tickWorld($world);
});

test('target death clears the mob target', function () use ($world, $spawn) {
    $player = spawnPlayer($world, 330, 400);
    $zombie = $spawn->spawnMob('Zombie', 331, 65, 400);

    for ($i = 0; $i < 10; $i++) {
        tickWorld($world);
        if ($zombie->getAIState()->targetEntity === $player->getId()) {
            break;
        }
    }
    ok($zombie->getAIState()->targetEntity === $player->getId(), 'zombie acquired target');

    // Kill the player: target should clear (despawned next tick).
    $kernel = \pocketmine\Kernel::getInstance();
    $kernel->getCombatService()->kill($player);
    tickWorld($world);
    tickWorld($world);
    ok($zombie->getAIState()->targetEntity === null, 'zombie cleared target after player death');

    // Idle mob stops: after losing its target it must not keep sliding.
    $vel = $zombie->getEntity()?->get(VelocityComponent::class);
    ok($vel !== null && $vel->x == 0.0 && $vel->z == 0.0, 'idle mob velocity zeroed after target loss');

    foreach ($world->getEntities() as $e) {
        $world->despawn($e);
    }
    tickWorld($world);
});

test('healthy passive mob never acquires or chases a player', function () use ($world, $spawn) {
    $player = spawnPlayer($world, 330, 420);
    $cow = $spawn->spawnMob('Cow', 331, 65, 420); // 1 block away, healthy

    for ($i = 0; $i < 20; $i++) {
        tickWorld($world);
    }
    ok($cow->getAIState()->targetEntity === null, 'healthy cow never acquires a target');
    $cowPos = $cow->getEntity()?->get(PositionComponent::class);
    $playerPos = $player->getEntity()?->get(PositionComponent::class);
    // Cow may wander, but should stay near the player (no chase behavior).
    ok(xzDist($cowPos, $playerPos) < 15, 'cow did not flee/chase far from the player');

    foreach ($world->getEntities() as $e) {
        $world->despawn($e);
    }
    tickWorld($world);
});

test('attack cooldown gates even the first hit', function () use ($world, $spawn, $kernel) {
    $player = spawnPlayer($world, 330, 440);
    $zombie = $spawn->spawnMob('Zombie', 331, 65, 440);

    $attackEvents = 0;
    $kernel->getEventPort()->subscribe(EntityDamageEvent::class, function (EntityDamageEvent $e) use (&$attackEvents) {
        if ($e->getCause() === EntityDamageEvent::CAUSE_ENTITY_ATTACK) {
            $attackEvents++;
        }
    });

    $ai = $zombie->getAIState();
    $ai->attackCooldown = 0; // allow immediate first attack
    tickWorld($world);
    $firstTick = $attackEvents;

    $ai->attackCooldown = 20; // blocked: next attack must wait 20 ticks
    for ($i = 0; $i < 19; $i++) {
        tickWorld($world);
    }
    same($firstTick, $attackEvents, 'no attack while cooldown active (19 ticks)');
    tickWorld($world); // tick 20: cooldown expires
    same($firstTick + 1, $attackEvents, 'attack fires once cooldown expires');

    foreach ($world->getEntities() as $e) {
        $world->despawn($e);
    }
    tickWorld($world);
});

exit(runTests());

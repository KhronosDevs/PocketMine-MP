<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\component\CollisionComponent;
use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\QueryBuilder;

/**
 * ECS core tests: spawn/despawn lifecycle, querying with component
 * predicates, EntityRef identity, and exact movement+gravity integration
 * over world ticks.
 */

$kernel = \pocketmine\bootstrap();
$world = $kernel->getWorld();
$refs = null;
QueryBuilder::clearCache();

test('spawn + query with/without components', function () use ($world, &$refs) {
    $moving = $world->spawn(
        (new EntityBuilder())
            ->at(0.0, 100.0, 0.0)
            ->with(new VelocityComponent(1.0, 0.0, 0.0))
    );
    $static = $world->spawn(
        (new EntityBuilder())
            ->at(5.0, 64.0, 5.0)
            ->with(new HealthComponent(20, 20))
    );

    QueryBuilder::clearCache();
    $withPos = $world->query()->with(PositionComponent::class)->build();
    same(2, $withPos->count(), 'two entities have positions');

    QueryBuilder::clearCache();
    $withVel = $world->query()->with(PositionComponent::class, VelocityComponent::class)->build();
    same(1, $withVel->count(), 'one entity has velocity');

    QueryBuilder::clearCache();
    $withoutVel = $world->query()->without(VelocityComponent::class)->build();
    same(1, $withoutVel->count(), 'one entity lacks velocity');

    QueryBuilder::clearCache();
    $withAny = $world->query()->withAny(VelocityComponent::class, HealthComponent::class)->build();
    same(2, $withAny->count(), 'both entities match withAny');

    $refs = [$moving, $static];
});

test('EntityRef identity, teleport, damage, heal', function () use ($world, &$refs) {
    ok($refs !== null, 'entities spawned in first test');
    $ref = $refs[0];

    ok($ref === EntityRef::get($ref->getId()), 'EntityRef is cached and stable');
    ok($ref->isValid(), 'ref is valid while entity lives');
    same($ref->getId(), $ref->getEntity()?->id, 'ref id matches entity id');

    ok($ref->teleport(10.0, 60.0, 10.0), 'teleport succeeds');
    $pos = $ref->getPosition();
    near(10.0, $pos->x, 1e-9, 'teleport x');
    near(60.0, $pos->y, 1e-9, 'teleport y');

    $health = $refs[1];
    ok(!$health->damage(5), 'damage does not kill at 15/20');
    near(15.0, $health->getHealth()?->current, 1e-9, 'health reduced');
    $health->heal(3);
    near(18.0, $health->getHealth()?->current, 1e-9, 'health healed');
    ok($health->damage(18), 'damage kills at 0/20');
});

test('tick integrates movement + gravity exactly', function () use ($world, &$refs) {
    // entity 0: position (10, 60, 10), velocity (1, 0, 0)
    $world->tick(0.05);
    $world->tick(0.05);

    $pos = $refs[0]->getPosition();
    near(10.1, $pos->x, 1e-6, 'x advanced by velocity * 2 ticks');
    // Tick 1: vy=0 keeps y at 60; tick 2: vy=-0.08 (1.6*dt) pulls it down 0.004.
    near(59.996, $pos->y, 1e-6, 'gravity affects y from the second tick');

    $vel = $refs[0]->getVelocity();
    near(-0.16, $vel->y, 1e-9, 'gravity applied twice (1.6 * 0.05 * 2)');
});

test('collidable entities integrate movement exactly once (no 2x speed)', function () use ($world) {
    // Regression: BlockCollisionSystem used the PENDING (already integrated)
    // position as its sweep base and integrated velocity AGAIN, so every
    // entity with a CollisionComponent (mobs, drops) moved at 2x speed.
    $e = $world->spawn(
        (new EntityBuilder())
            ->at(0.0, 200.0, 0.0)
            ->with(new VelocityComponent(1.0, 0.0, 0.0))
            ->with(new CollisionComponent(width: 0.6, height: 1.8))
    );
    for ($i = 0; $i < 20; $i++) {
        $world->tick(0.05);
    }
    $pos = $e->getPosition();
    near(1.0, $pos->x, 1e-6, '20 ticks at 1 blk/s moves exactly 1 block (not 2)');
    $world->despawn($e->getEntity());
    $world->tick(0.05);
});

test('despawn removes from world and queries', function () use ($world, &$refs) {
    $world->despawn($refs[1]->getEntity());
    $world->tick(0.05); // flushes removal

    ok(!$refs[1]->isValid(), 'despawned ref is invalid');
    same(null, $world->getEntity($refs[1]->getId()), 'entity removed from world');

    QueryBuilder::clearCache();
    $withPos = $world->query()->with(PositionComponent::class)->build();
    same(1, $withPos->count(), 'one entity remains after despawn');
});

exit(runTests());

<?php
declare(strict_types=1);
require __DIR__ . '/../autoload.php';

use pocketmine\adapter\driven\threading\ArchetypeSnapshot;
use pocketmine\adapter\driven\threading\EcsSystemTask;
use pocketmine\adapter\driven\threading\ParallelResult;
use pocketmine\adapter\driven\threading\PmmpThreadPool;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\ecs\ComponentRegistry;
use pocketmine\core\ecs\ResourceRegistry;
use pocketmine\core\ecs\SystemPhase;
use pocketmine\core\ecs\SystemScheduler;
use pocketmine\core\ecs\World;
use pocketmine\core\system\MovementSystem;
use pocketmine\core\system\PhysicsSystem;

require __DIR__ . '/helpers.php';

// ---- Test 1: ArchetypeSnapshot round-trip via JSON ----
test('ArchetypeSnapshot JSON round-trip preserves float arrays', function (): void {
    $snap = ArchetypeSnapshot::fromPayload([
        'positionsX' => [1.5, 2.0, -3.7],
        'positionsY' => [64.0, 65.5, 63.0],
        'positionsZ' => [0.0, 1.0, 2.0],
        'velocitiesX' => [0.1, -0.2, 0.0],
        'velocitiesY' => [0.0, 1.5, -0.5],
        'velocitiesZ' => [0.3, 0.0, 0.1],
    ], 3, 0.05);

    ok($snap->count === 3, 'count preserved');
    ok($snap->deltaTime === 0.05, 'deltaTime preserved');

    $payload = $snap->getPayload();
    ok(count($payload['positionsX']) === 3, 'positionsX count');
    ok($payload['positionsX'][0] === 1.5, 'first position X preserved');
    ok($payload['positionsX'][2] === -3.7, 'third position X preserved');
    ok($payload['velocitiesY'][1] === 1.5, 'velocity Y preserved');
});

// ---- Test 2: ParallelResult round-trip ----
test('ParallelResult JSON round-trip preserves pending data', function (): void {
    $result = new ParallelResult();
    $result->setPayload([
        'pendingPositionsX' => [10.0, 20.0],
        'pendingPositionsY' => [64.0, 65.0],
        'pendingPositionsZ' => [0.0, 1.0],
        'pendingVelocitiesX' => [0.0, 0.0],
        'pendingVelocitiesY' => [-1.6, 0.0],
        'pendingVelocitiesZ' => [0.0, 0.0],
    ], 2);

    $payload = $result->getPayload();
    near(10.0, (float)$payload['pendingPositionsX'][0], 0.001, 'pending X');
    near(-1.6, (float)$payload['pendingVelocitiesY'][0], 0.001, 'pending VY (gravity)');
    ok($result->count === 2, 'result count');
});

// ---- Test 3: MovementSystem::computeOnSnapshot matches runParallel ----
test('MovementSystem computeOnSnapshot produces same result as runParallel', function (): void {
    // Set up an archetype with known positions + velocities
    $reg = new ComponentRegistry();
    $reg->register(PositionComponent::class);
    $reg->register(VelocityComponent::class);

    $archetype = $reg->getArchetype([PositionComponent::class, VelocityComponent::class]);

    $p1 = new PositionComponent(10.0, 64.0, 0.0);
    $v1 = new VelocityComponent(1.0, 0.0, 0.5);
    $p2 = new PositionComponent(20.0, 65.0, 10.0);
    $v2 = new VelocityComponent(-0.5, 0.0, 0.3);

    $e1 = new \pocketmine\core\ecs\Entity(100, [
        PositionComponent::class => $p1,
        VelocityComponent::class => $v1,
    ]);
    $e2 = new \pocketmine\core\ecs\Entity(101, [
        PositionComponent::class => $p2,
        VelocityComponent::class => $v2,
    ]);
    $archetype->addEntity($e1);
    $archetype->addEntity($e2);

    $dt = 0.05;

    // Method A: runParallel (in-place, writes to pending)
    $p1a = clone $p1;
    $v1a = clone $v1;
    $p2a = clone $p2;
    $v2a = clone $v2;
    // Reset pending
    $p1a->pending = null;
    $p2a->pending = null;

    // Method B: computeOnSnapshot (cross-thread)
    $snap = MovementSystem::snapshotArchetype($archetype, $dt);
    $result = new ParallelResult();
    MovementSystem::computeOnSnapshot($snap, $result);

    $payload = $result->getPayload();

    // Expected: x + vx * dt
    ok(abs($payload['pendingPositionsX'][0] - (10.0 + 1.0 * $dt)) < 0.001, 'entity 1 X after movement');
    ok(abs($payload['pendingPositionsY'][0] - (64.0 + 0.0 * $dt)) < 0.001, 'entity 1 Y after movement');
    ok(abs($payload['pendingPositionsZ'][0] - (0.0 + 0.5 * $dt)) < 0.001, 'entity 1 Z after movement');
    ok(abs($payload['pendingPositionsX'][1] - (20.0 + (-0.5) * $dt)) < 0.001, 'entity 2 X after movement');
});

// ---- Test 4: PhysicsSystem::computeOnSnapshot applies gravity ----
test('PhysicsSystem computeOnSnapshot applies gravity correctly', function (): void {
    $snap = ArchetypeSnapshot::fromPayload([
        'positionsX' => [0.0],
        'positionsY' => [100.0],
        'positionsZ' => [0.0],
        'velocitiesX' => [0.0],
        'velocitiesY' => [0.0],
        'velocitiesZ' => [0.0],
    ], 1, 0.05);

    $result = new ParallelResult();
    PhysicsSystem::computeOnSnapshot($snap, $result);

    $payload = $result->getPayload();
    // Gravity: vy = 0 - 1.6 * 0.05 = -0.08
    ok(abs($payload['pendingVelocitiesY'][0] - (-1.6 * 0.05)) < 0.001, 'gravity applied');
    // Position: y = 100 + 0 * 0.05 = 100
    ok(abs($payload['pendingPositionsY'][0] - 100.0) < 0.001, 'position unchanged (zero velocity)');
    // Terminal velocity cap: vy = -80 should clamp to -78.4
    $snap2 = ArchetypeSnapshot::fromPayload([
        'positionsX' => [0.0], 'positionsY' => [100.0], 'positionsZ' => [0.0],
        'velocitiesX' => [0.0], 'velocitiesY' => [-80.0], 'velocitiesZ' => [0.0],
    ], 1, 0.05);
    $result2 = new ParallelResult();
    PhysicsSystem::computeOnSnapshot($snap2, $result2);
    $payload2 = $result2->getPayload();
    ok($payload2['pendingVelocitiesY'][0] >= -78.4, 'terminal velocity capped');
});

// ---- Test 5: PmmpThreadPool submits tasks to real workers ----
test('PmmpThreadPool dispatches EcsSystemTask to real worker threads', function (): void {
    $pool = new PmmpThreadPool(2);

    $snap = ArchetypeSnapshot::fromPayload([
        'positionsX' => [0.0, 10.0],
        'positionsY' => [64.0, 65.0],
        'positionsZ' => [0.0, 0.0],
        'velocitiesX' => [1.0, -1.0],
        'velocitiesY' => [0.0, 0.0],
        'velocitiesZ' => [0.0, 0.0],
    ], 2, 0.1);

    $result = new ParallelResult();
    $task = new EcsSystemTask($snap, $result, 'movement');
    $pool->submitTask($task);

    $deadline = microtime(true) + 5.0;
    $collected = false;
    while (!$collected && microtime(true) < $deadline) {
        $pool->collectTasks(function (EcsSystemTask $t) use (&$collected): bool {
            if ($t->result->done) {
                $collected = true;
                return true;
            }
            return false;
        });
        if (!$collected) usleep(500);
    }
    $pool->shutdown();

    ok($collected, 'task collected from pool');
    ok($result->done, 'result marked done');
    $payload = $result->getPayload();
    ok(abs($payload['pendingPositionsX'][0] - 0.1) < 0.001, 'entity 0 moved 0.1 blocks (1.0 * 0.1)');
    ok(abs($payload['pendingPositionsX'][1] - 9.9) < 0.001, 'entity 1 moved -0.1 blocks (-1.0 * 0.1)');
});

// ---- Test 6: SystemScheduler with real pool dispatches movement to workers ----
test('SystemScheduler dispatches parallel systems to real worker pool', function (): void {
    $pool = new PmmpThreadPool(2);
    $scheduler = new SystemScheduler($pool);
    $scheduler->register(new MovementSystem(), SystemPhase::PARALLEL);

    $reg = new ComponentRegistry();
    $res = new ResourceRegistry();
    $reg->register(PositionComponent::class);
    $reg->register(VelocityComponent::class);
    $world = new World($reg, $res, $scheduler);

    // Spawn 20 entities (above the 16-entity threshold for pool dispatch)
    for ($i = 0; $i < 20; $i++) {
        $entity = new \pocketmine\core\ecs\Entity(1000 + $i, [
            PositionComponent::class => new PositionComponent((float)$i, 64.0, 0.0),
            VelocityComponent::class => new VelocityComponent(1.0, 0.0, 0.0),
        ]);
        $world->addEntity($entity);
    }

    // Run one tick — the scheduler should dispatch to the real pool
    $world->tick(0.05);

    // Verify positions advanced by 1.0 * 0.05 = 0.05
    $ok = true;
    for ($i = 0; $i < 20; $i++) {
        $entity = $world->getEntity(1000 + $i);
        $pos = $entity?->get(PositionComponent::class);
        if ($pos === null || abs($pos->x - ((float)$i + 0.05)) > 0.01) {
            $ok = false;
            break;
        }
    }
    ok($ok, 'all 20 entities moved by velocity after real pool dispatch');
    $pool->shutdown();
});

// ---- Test 7: applyResult writes pending correctly ----
test('applyResult writes pending position buffers on real components', function (): void {
    $reg = new ComponentRegistry();
    $reg->register(PositionComponent::class);
    $reg->register(VelocityComponent::class);

    $archetype = $reg->getArchetype([PositionComponent::class, VelocityComponent::class]);

    $p1 = new PositionComponent(0.0, 0.0, 0.0);
    $v1 = new VelocityComponent(5.0, 0.0, 0.0);
    $e1 = new \pocketmine\core\ecs\Entity(200, [
        PositionComponent::class => $p1,
        VelocityComponent::class => $v1,
    ]);
    $archetype->addEntity($e1);

    $result = new ParallelResult();
    $result->setPayload([
        'pendingPositionsX' => [1.0],
        'pendingPositionsY' => [2.0],
        'pendingPositionsZ' => [3.0],
        'pendingVelocitiesX' => [],
        'pendingVelocitiesY' => [],
        'pendingVelocitiesZ' => [],
    ], 1);

    MovementSystem::applyResult($archetype, $result);

    ok($p1->pending !== null, 'pending buffer created');
    ok($p1->pending->x === 1.0, 'pending X written');
    ok($p1->pending->y === 2.0, 'pending Y written');
    ok($p1->pending->z === 3.0, 'pending Z written');

    // applyPending should commit to the main fields
    $p1->applyPending();
    ok($p1->x === 1.0, 'committed X');
    ok($p1->y === 2.0, 'committed Y');
    ok($p1->z === 3.0, 'committed Z');
});

exit(runTests());

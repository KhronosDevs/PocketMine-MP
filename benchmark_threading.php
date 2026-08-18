<?php
declare(strict_types=1);

require __DIR__ . '/autoload.php';

use pocketmine\adapter\driven\threading\ArchetypeSnapshot;
use pocketmine\adapter\driven\threading\EcsSystemTask;
use pocketmine\adapter\driven\threading\ParallelResult;
use pocketmine\adapter\driven\threading\PmmpThreadPool;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\ecs\ComponentRegistry;
use pocketmine\core\ecs\World;
use pocketmine\core\ecs\SystemScheduler;
use pocketmine\core\ecs\ResourceRegistry;
use pocketmine\core\ecs\SystemPhase;
use pocketmine\core\system\MovementSystem;
use pocketmine\core\system\PhysicsSystem;
use pocketmine\core\system\EffectSystem;

echo "=== Khronos ECS Threading Benchmark ===\n\n";

$entityCounts = [10, 50, 100, 500, 1000, 2000];
$iterations = 50;
$deltaTime = 0.05; // 20 TPS

// ── Helper: build a world with N non-player entities ──
function buildWorldWithEntities(int $count, SystemScheduler $scheduler): World {
    $reg = new ComponentRegistry();
    $reg->register(PositionComponent::class);
    $reg->register(VelocityComponent::class);
    $reg->register(EffectComponent::class);

    $res = new ResourceRegistry();
    $world = new World($reg, $res, $scheduler);

    for ($i = 0; $i < $count; $i++) {
        $entity = new \pocketmine\core\ecs\Entity(10000 + $i, [
            PositionComponent::class => new PositionComponent(
                (float)(mt_rand(0, 500) - 250),
                (float)mt_rand(10, 100),
                (float)(mt_rand(0, 500) - 250),
            ),
            VelocityComponent::class => new VelocityComponent(
                (float)(mt_rand(-100, 100) / 100.0),
                (float)(mt_rand(-50, 50) / 100.0),
                (float)(mt_rand(-100, 100) / 100.0),
            ),
        ]);
        $world->addEntity($entity);
    }
    return $world;
}

// ── Benchmark 1: Direct computeOnSnapshot (pure computation, no pool) ──
echo "1. Pure computation (computeOnSnapshot on main thread, no pool overhead)\n";
echo str_pad("Entities", 10) . str_pad("Cycles", 8) . str_pad("Total (ms)", 14) . str_pad("Per-cycle (µs)", 16) . "Throughput\n";
echo str_repeat("─", 68) . "\n";

foreach ($entityCounts as $n) {
    $snap = ArchetypeSnapshot::fromPayload(
        array_fill(0, $n, 0), // placeholder
        $n, $deltaTime
    );

    // Build actual payload
    $px = []; $py = []; $pz = [];
    $vx = []; $vy = []; $vz = [];
    for ($i = 0; $i < $n; $i++) {
        $px[] = (float)(mt_rand(0, 500) - 250);
        $py[] = (float)mt_rand(10, 100);
        $pz[] = (float)(mt_rand(0, 500) - 250);
        $vx[] = (float)(mt_rand(-100, 100) / 100.0);
        $vy[] = (float)(mt_rand(-50, 50) / 100.0);
        $vz[] = (float)(mt_rand(-100, 100) / 100.0);
    }
    $snap = ArchetypeSnapshot::fromPayload([
        'positionsX' => $px, 'positionsY' => $py, 'positionsZ' => $pz,
        'velocitiesX' => $vx, 'velocitiesY' => $vy, 'velocitiesZ' => $vz,
    ], $n, $deltaTime);

    // Movement
    $t0 = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $result = new ParallelResult();
        MovementSystem::computeOnSnapshot($snap, $result);
    }
    $moveTime = (microtime(true) - $t0) * 1000;

    // Physics
    $t0 = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $result = new ParallelResult();
        PhysicsSystem::computeOnSnapshot($snap, $result);
    }
    $physTime = (microtime(true) - $t0) * 1000;

    $totalMs = $moveTime + $physTime;
    $perCycleUs = ($totalMs / $iterations) * 1000;
    $throughput = ($n / ($totalMs / $iterations)) * 1000;

    echo str_pad((string)$n, 10)
        . str_pad((string)$iterations, 8)
        . str_pad(sprintf('%.1f', $totalMs), 14)
        . str_pad(sprintf('%.1f', $perCycleUs), 16)
        . sprintf('%.0f ent/s', $throughput) . "\n";
}

// ── Benchmark 2: Full snapshot+compute+merge cycle (main thread, no pool) ──
echo "\n2. Full sync cycle (snapshot → compute → merge, no pool)\n";
echo str_pad("Entities", 10) . str_pad("Cycles", 8) . str_pad("Total (ms)", 14) . str_pad("Per-cycle (µs)", 16) . "Throughput\n";
echo str_repeat("─", 68) . "\n";

foreach ($entityCounts as $n) {
    $scheduler = new SystemScheduler(new PmmpThreadPool(1));
    $scheduler->register(new MovementSystem(), SystemPhase::PARALLEL);
    $scheduler->register(new PhysicsSystem(), SystemPhase::PARALLEL);
    $world = buildWorldWithEntities($n, $scheduler);

    $t0 = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $world->tick($deltaTime);
    }
    $totalMs = (microtime(true) - $t0) * 1000;
    $perCycleUs = ($totalMs / $iterations) * 1000;
    $throughput = ($n / ($totalMs / $iterations)) * 1000;

    echo str_pad((string)$n, 10)
        . str_pad((string)$iterations, 8)
        . str_pad(sprintf('%.1f', $totalMs), 14)
        . str_pad(sprintf('%.1f', $perCycleUs), 16)
        . sprintf('%.0f ent/s', $throughput) . "\n";
}

// ── Benchmark 3: Full cycle with real pmmpthread Pool ──
echo "\n3. Real pool cycle (snapshot → pool dispatch → compute on worker → merge)\n";
echo str_pad("Entities", 10) . str_pad("Cycles", 8) . str_pad("Total (ms)", 14) . str_pad("Per-cycle (µs)", 16) . "Throughput\n";
echo str_repeat("─", 68) . "\n";

$workerCounts = [2, 4];

foreach ($workerCounts as $wc) {
    echo "── Workers: $wc ──\n";
    foreach ($entityCounts as $n) {
        $pool = new PmmpThreadPool($wc);
        $scheduler = new SystemScheduler($pool);
        $scheduler->register(new MovementSystem(), SystemPhase::PARALLEL);
        $scheduler->register(new PhysicsSystem(), SystemPhase::PARALLEL);
        $world = buildWorldWithEntities($n, $scheduler);

        $t0 = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $world->tick($deltaTime);
        }
        $totalMs = (microtime(true) - $t0) * 1000;
        $perCycleUs = ($totalMs / $iterations) * 1000;
        $throughput = ($n / ($totalMs / $iterations)) * 1000;

        echo str_pad((string)$n, 10)
            . str_pad((string)$iterations, 8)
            . str_pad(sprintf('%.1f', $totalMs), 14)
            . str_pad(sprintf('%.1f', $perCycleUs), 16)
            . sprintf('%.0f ent/s', $throughput) . "\n";

        $pool->shutdown();
    }
}

// ── Benchmark 4: Pool dispatch overhead measurement ──
echo "\n4. Pool dispatch overhead (JSON encode/decode + submit + collect)\n";
echo str_pad("Entities", 10) . str_pad("Snapshot (µs)", 15) . str_pad("Pool+Collect (µs)", 18) . str_pad("Merge (µs)", 12) . "Total (µs)\n";
echo str_repeat("─", 65) . "\n";

$pool = new PmmpThreadPool(4);
foreach ([50, 100, 500, 1000, 2000] as $n) {
    $px = []; $py = []; $pz = [];
    $vx = []; $vy = []; $vz = [];
    for ($i = 0; $i < $n; $i++) {
        $px[] = (float)(mt_rand(0, 500) - 250);
        $py[] = (float)mt_rand(10, 100);
        $pz[] = (float)(mt_rand(0, 500) - 250);
        $vx[] = (float)(mt_rand(-100, 100) / 100.0);
        $vy[] = (float)(mt_rand(-50, 50) / 100.0);
        $vz[] = (float)(mt_rand(-100, 100) / 100.0);
    }

    $totalSnap = 0; $totalPool = 0; $totalMerge = 0;
    for ($i = 0; $i < 20; $i++) {
        $t0 = microtime(true);
        $snap = ArchetypeSnapshot::fromPayload([
            'positionsX' => $px, 'positionsY' => $py, 'positionsZ' => $pz,
            'velocitiesX' => $vx, 'velocitiesY' => $vy, 'velocitiesZ' => $vz,
        ], $n, $deltaTime);
        $totalSnap += (microtime(true) - $t0) * 1e6;

        $result = new ParallelResult();
        $task = new EcsSystemTask($snap, $result, 'movement');
        $t1 = microtime(true);
        $pool->submitTask($task);
        $collected = false;
        while (!$collected) {
            $pool->collectTasks(function (EcsSystemTask $t) use (&$collected): bool {
                if ($t->result->done) { $collected = true; return true; }
                return false;
            });
            if (!$collected) usleep(100);
        }
        $totalPool += (microtime(true) - $t1) * 1e6;

        $t2 = microtime(true);
        $payload = $result->getPayload();
        $totalMerge += (microtime(true) - $t2) * 1e6;
    }

    $snapUs = $totalSnap / 20;
    $poolUs = $totalPool / 20;
    $mergeUs = $totalMerge / 20;
    $totalUs = $snapUs + $poolUs + $mergeUs;

    echo str_pad((string)$n, 10)
        . str_pad(sprintf('%.0f', $snapUs), 15)
        . str_pad(sprintf('%.0f', $poolUs), 18)
        . str_pad(sprintf('%.0f', $mergeUs), 12)
        . sprintf('%.0f', $totalUs) . "\n";
}
$pool->shutdown();

echo "\n=== Benchmark complete ===\n";

#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Representative-tick CPU profile (FFI hotspots exploration, branch
 * explore/ffi-hotspots-2).
 *
 * Boots the real ECS stack and simulates a small survival world: a mix of
 * player entities (client-authoritative, excluded from server gravity) and
 * moving mobs (server-side gravity + movement + block collision), then
 * measures:
 *   1. whole-tick cost back-to-back (hot, no 50ms pacing) — the true CPU
 *      cost per tick at saturation,
 *   2. phase breakdown (mirror / tick / drain / balance / rest) from the
 *      kernel's own phase profiler,
 *   3. per-system isolation: each sequential system's run() is timed on the
 *      live world state so we can see which system actually owns the tick.
 *
 * Usage: bin/php7/bin/php bench/01_tick_profile.php [players] [mobs] [ticks]
 */

require_once __DIR__ . '/../autoload.php';

use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\system\BlockCollisionSystem;
use pocketmine\core\system\MovementSystem;
use pocketmine\core\system\PhysicsSystem;

$players = (int)($argv[1] ?? 50);
$mobs = (int)($argv[2] ?? 500);
$ticks = (int)($argv[3] ?? 200);

putenv('KHRONOS_FAST_TICKS=1');

$kernel = \pocketmine\bootstrap();
$kernel->setAutoShutdownOnRun(false);

$serverCfg = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ServerConfig::class);
if ($serverCfg instanceof \pocketmine\core\resource\ServerConfig) {
    $serverCfg->spawnMobs = false;
    $serverCfg->spawnAnimals = false;
}

$world = $kernel->getWorld();

for ($i = 0; $i < $players; $i++) {
    $world->spawn(
        (new EntityBuilder())
            ->at(($i % 50) * 1.5, 70.0 + ($i % 3), intdiv($i, 50) * 1.5)
            ->with(new VelocityComponent(0.0, 0.0, 0.0))
            ->withTag(PlayerTag::class)
    );
}
for ($i = 0; $i < $mobs; $i++) {
    $world->spawn(
        (new EntityBuilder())
            ->at(($i % 100) * 1.5, 64.0 + ($i % 8), intdiv($i, 100) * 1.5)
            ->with(new VelocityComponent(0.5, 0.0, 0.3))
    );
}

echo "players={$players} mobs={$mobs} ticks={$ticks}\n";

// Warm up (world gen + archetype creation).
for ($i = 0; $i < 40; $i++) {
    $world->tick(0.05);
}

$kernel->setPhaseProfiling(true);
$kernel->run($ticks);

// Per-system decomposition: time each system exactly as the scheduler drives
// it (sequential systems run(), parallel systems runParallel() over their
// target archetypes, collision system run()), back-to-back, on the live world.
$scheduler = $world->getSystemScheduler();
$ref = new ReflectionClass($scheduler);
$systems = [];
foreach (['sequentialSystems', 'parallelSystems', 'chunkParallelSystems'] as $prop) {
    $p = $ref->getProperty($prop);
    $p->setAccessible(true);
    foreach ($p->getValue($scheduler) as $sys) {
        $systems[] = $sys;
    }
}
$p = $ref->getProperty('collisionSystem');
$p->setAccessible(true);
$collision = $p->getValue($scheduler);

$hotTicks = max(20, min(200, $ticks));
$perSystem = [];
$tick = function (float $dt) use ($systems, $collision, $world): void {
    foreach ($systems as $sys) {
        if ($sys instanceof \pocketmine\core\ecs\ParallelSystem) {
            foreach ($sys->getTargetArchetypes($world) as $arch) {
                $sys->runParallel($arch, $dt);
            }
        } elseif ($sys instanceof \pocketmine\core\ecs\ChunkParallelSystem) {
            $sys->run($world, $dt);
        } elseif ($sys instanceof \pocketmine\core\ecs\System) {
            $sys->run($world, $dt);
        }
    }
    if ($collision !== null) {
        $collision->run($world, $dt);
    }
    $world->applyPendingComponents();
};

// Warm the per-system path once, then measure each system in isolation.
$tick(0.05);
foreach ($systems as $sys) {
    $name = (new ReflectionClass($sys))->getShortName();
    $t0 = hrtime(true);
    for ($t = 0; $t < $hotTicks; $t++) {
        if ($sys instanceof \pocketmine\core\ecs\ParallelSystem) {
            foreach ($sys->getTargetArchetypes($world) as $arch) {
                $sys->runParallel($arch, 0.05);
            }
        } elseif ($sys instanceof \pocketmine\core\ecs\System) {
            $sys->run($world, 0.05);
        }
    }
    $perSystem[$name] = round((hrtime(true) - $t0) / 1e6 / $hotTicks, 4);
}
if ($collision !== null) {
    $name = (new ReflectionClass($collision))->getShortName();
    $t0 = hrtime(true);
    for ($t = 0; $t < $hotTicks; $t++) {
        $collision->run($world, 0.05);
    }
    $perSystem[$name] = round((hrtime(true) - $t0) / 1e6 / $hotTicks, 4);
}

// Hot back-to-back whole-tick cost at saturation.
$instrument = null;
if (property_exists(\pocketmine\core\ecs\SystemScheduler::class, 'instrument')) {
    \pocketmine\core\ecs\SystemScheduler::$instrument = [
        'sequential_ms' => 0.0,
        'snapshot_ms' => 0.0,
        'submit_ms' => 0.0,
        'collect_ms' => 0.0,
        'collect_call_ms' => 0.0,
        'apply_decode_ms' => 0.0,
        'poll_cycles' => 0,
        'poll_usleep_ms' => 0.0,
        'snapshots_count' => 0,
        'snap_samples' => [],
    ];
    $instrument = true;
}
$t0 = hrtime(true);
for ($t = 0; $t < $hotTicks; $t++) {
    $world->tick(0.05);
}
$hotMeanMs = (hrtime(true) - $t0) / 1e6 / $hotTicks;

$schedulerInstrument = null;
if ($instrument !== null) {
    $schedulerInstrument = \pocketmine\core\ecs\SystemScheduler::$instrument;
    $snapCount = $schedulerInstrument['snapshots_count'];
    $snapSamples = $schedulerInstrument['snap_samples'];
    foreach ($schedulerInstrument as $k => $v) {
        if ($k === 'snap_samples') {
            continue;
        }
        $schedulerInstrument[$k] = round($v / $hotTicks, 4);
    }
    $snapStats = ['n' => count($snapSamples)];
    if ($snapSamples) {
        sort($snapSamples);
        $snapStats['median_ms'] = round($snapSamples[intdiv(count($snapSamples), 2)], 4);
        $snapStats['p95_ms'] = round($snapSamples[min(count($snapSamples) - 1, (int)(count($snapSamples) * 0.95))], 4);
        $snapStats['max_ms'] = round($snapSamples[count($snapSamples) - 1], 4);
        $snapStats['mean_ms'] = round(array_sum($snapSamples) / count($snapSamples), 4);
    }
}

// --- Isolate the snapshot JSON cost on the live mob archetype -------------
$arch = null;
foreach ($world->query()->with(PositionComponent::class, VelocityComponent::class)->without(PlayerTag::class)->build()->archetypes($world->getComponentRegistry()) as $a) {
    $arch = $a;
}
$snapTime = 0.0;
$jsonEnc = 0.0;
$jsonDec = 0.0;
$snap = null;
for ($i = 0; $i < 50; $i++) {
    $t0 = hrtime(true);
    $snap = \pocketmine\core\system\MovementSystem::snapshotArchetype($arch, 0.05);
    $snapTime += (hrtime(true) - $t0) / 1e6;
}
$payload = $snap->getPayload();
$t0 = hrtime(true);
for ($i = 0; $i < 500; $i++) {
    json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
$jsonEnc = (hrtime(true) - $t0) / 1e6 / 500;
$encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$t0 = hrtime(true);
for ($i = 0; $i < 500; $i++) {
    json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
}
$jsonDec = (hrtime(true) - $t0) / 1e6 / 500;
$snapMean = $snapTime / 50;

$codec = \pocketmine\adapter\driven\threading\SnapshotCodec::class;
$binEnc = null;
$binDec = null;
$binBytes = null;
if (class_exists($codec)) {
    $binSections = [
        $payload['positionsX'], $payload['positionsY'], $payload['positionsZ'],
        $payload['velocitiesX'], $payload['velocitiesY'], $payload['velocitiesZ'],
    ];
    $t0 = hrtime(true);
    for ($i = 0; $i < 500; $i++) {
        $codec::encode($binSections);
    }
    $binEnc = (hrtime(true) - $t0) / 1e6 / 500;
    $binBlob = $codec::encode($binSections);
    $t0 = hrtime(true);
    for ($i = 0; $i < 500; $i++) {
        $codec::decode($binBlob);
    }
    $binDec = (hrtime(true) - $t0) / 1e6 / 500;
    $binBytes = strlen($binBlob);
}

echo json_encode([
    'players' => $players,
    'mobs' => $mobs,
    'entities' => count($world->getEntities()),
    'hot_tick_mean_ms' => round($hotMeanMs, 4),
    'per_system_ms' => $perSystem,
    'scheduler_instrument_ms_per_tick' => $schedulerInstrument,
    'snapshots_per_tick' => $snapCount / $hotTicks,
    'snapshot_call_stats_ms' => $snapStats ?? null,
    'snapshot_isolation_ms' => [
        'snapshotArchetype' => round($snapMean, 4),
        'json_encode_only' => round($jsonEnc, 4),
        'json_decode_only' => round($jsonDec, 4),
        'pack_encode_only' => $binEnc !== null ? round($binEnc, 4) : null,
        'pack_decode_only' => $binDec !== null ? round($binDec, 4) : null,
        'payload_count' => $snap->count,
        'payload_json_bytes' => strlen($encoded),
        'payload_binary_bytes' => $binBytes,
        'mob_archetype_entities' => $arch !== null ? $arch->count() : null,
    ],
    'phases' => $kernel->getPhaseStats(),
    'tick_stats' => $kernel->getTickStats(),
], JSON_PRETTY_PRINT) . "\n";

$kernel->shutdown();
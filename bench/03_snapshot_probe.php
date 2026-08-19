#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Probe: replicate SystemScheduler's exact snapshot sequence and time it. */
require_once __DIR__ . '/../autoload.php';

use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\system\MovementSystem;
use pocketmine\core\system\PhysicsSystem;

putenv('KHRONOS_FAST_TICKS=1');
$players = (int)($argv[1] ?? 50);
$mobs = (int)($argv[2] ?? 500);

$kernel = \pocketmine\bootstrap();
$kernel->setAutoShutdownOnRun(false);
$cfg = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ServerConfig::class);
if ($cfg instanceof \pocketmine\core\resource\ServerConfig) {
    $cfg->spawnMobs = false;
    $cfg->spawnAnimals = false;
}
$world = $kernel->getWorld();
for ($i = 0; $i < $players; $i++) {
    $world->spawn((new EntityBuilder())->at(($i % 50) * 1.5, 70.0 + ($i % 3), intdiv($i, 50) * 1.5)
        ->with(new VelocityComponent(0.0, 0.0, 0.0))->withTag(PlayerTag::class));
}
for ($i = 0; $i < $mobs; $i++) {
    $world->spawn((new EntityBuilder())->at(($i % 100) * 1.5, 64.0 + ($i % 8), intdiv($i, 100) * 1.5)
        ->with(new VelocityComponent(0.5, 0.0, 0.3)));
}
for ($i = 0; $i < 40; $i++) {
    $world->tick(0.05);
}
$kernel->run(100);

// Enumerate the exact archetypes each system targets.
$mov = [];
foreach ((new MovementSystem())->getTargetArchetypes($world) as $a) {
    $mov[] = [$a, $a->count()];
}
$phy = [];
foreach ((new PhysicsSystem())->getTargetArchetypes($world) as $a) {
    $phy[] = [$a, $a->count()];
}
printf("movement archetypes: %s\n", json_encode(array_map(fn($x) => $x[1], $mov)));
printf("physics archetypes:  %s\n", json_encode(array_map(fn($x) => $x[1], $phy)));

$iters = 200;
// Replicate the scheduler snapshot sequence, one full "tick" of snapshots.
$tickSnap = function () use ($mov, $phy): void {
    foreach ($mov as [$a]) {
        MovementSystem::snapshotArchetype($a, 0.05);
    }
    foreach ($phy as [$a]) {
        PhysicsSystem::snapshotArchetype($a, 0.05);
    }
};
for ($i = 0; $i < 20; $i++) {
    $tickSnap();
}
$t0 = hrtime(true);
for ($i = 0; $i < $iters; $i++) {
    $tickSnap();
}
$total = (hrtime(true) - $t0) / 1e6 / $iters;

// Per-archetype timing.
$per = [];
foreach ($mov as [$a]) {
    $t0 = hrtime(true);
    for ($i = 0; $i < $iters; $i++) {
        MovementSystem::snapshotArchetype($a, 0.05);
    }
    $per[] = 'mov(count=' . $a->count() . ') ' . round((hrtime(true) - $t0) / 1e6 / $iters, 4) . 'ms';
}
foreach ($phy as [$a]) {
    $t0 = hrtime(true);
    for ($i = 0; $i < $iters; $i++) {
        PhysicsSystem::snapshotArchetype($a, 0.05);
    }
    $per[] = 'phy(count=' . $a->count() . ') ' . round((hrtime(true) - $t0) / 1e6 / $iters, 4) . 'ms';
}
printf("full snapshot sequence per tick: %.3f ms\n", $total);
foreach ($per as $p) {
    echo "  $p\n";
}

$kernel->shutdown();
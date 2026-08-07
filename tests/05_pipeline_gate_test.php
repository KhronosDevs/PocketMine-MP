<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\ecs\EntityBuilder;

/**
 * Phase 9 - region pipeline, gate (compare) mode.
 *
 * Entities are mirrored to the RegionThread each tick; the worker integrates
 * position from velocity + gravity in lockstep and pushes results back. In
 * gate mode the main thread still simulates and the worker results must match
 * the main-thread result bit-for-bit: mismatches must be zero.
 */

// --- Kernel A: determinism gate ------------------------------------------
$kernelA = \pocketmine\bootstrap();
$worldA = $kernelA->getWorld();
$kernelA->setRegionPipelineEnabled(true);

$count = 40;
$refs = [];
for ($i = 0; $i < $count; $i++) {
    $refs[] = $worldA->spawn(
        (new EntityBuilder())
            ->at($i * 1.5, 64.0, $i * 1.5)
            ->with(new \pocketmine\core\component\VelocityComponent(1.0, 0.0, 0.5))
    );
}

test('gate mode: worker results match main-thread simulation', function () use ($kernelA, $refs) {
    $kernelA->run(5);

    $stats = $kernelA->getRegionPipelineStats();
    ok($stats['mirrored'] >= 40, 'entities mirrored each tick, got ' . $stats['mirrored']);
    ok($stats['received'] >= 40, 'worker results received, got ' . $stats['received']);
    ok($stats['compared'] >= 40, 'results compared against main thread, got ' . $stats['compared']);
    same(0, $stats['mismatches'], 'zero mismatches (worker == main-thread simulation)');
    same(0, $stats['stale'], 'no results for dead entities');

    // Movement still happens on the main thread and matches expectation.
    foreach ($refs as $i => $ref) {
        $pos = $ref->getPosition();
        near($i * 1.5 + 0.25, $pos->x, 1e-6, "entity $i x advanced by 5 * 0.05");
        near($i * 1.5 + 0.125, $pos->z, 1e-6, "entity $i z advanced by 5 * 0.05");
    }
});

// --- Kernel B: despawn sync (removal mid-run, live worker) ----------------
$kernelB = \pocketmine\bootstrap();
$worldB = $kernelB->getWorld();
$kernelB->setRegionPipelineEnabled(true);

$brefs = [];
for ($i = 0; $i < 10; $i++) {
    $brefs[] = $worldB->spawn(
        (new EntityBuilder())
            ->at($i * 2.0, 64.0, 0.0)
            ->with(new \pocketmine\core\component\VelocityComponent(0.0, 0.0, 0.0))
    );
}
$worldB->despawn($brefs[0]->getEntity()); // removed before the run begins

test('gate mode: despawn sync + stale result handling', function () use ($kernelB) {
    $kernelB->run(2);

    $stats = $kernelB->getRegionPipelineStats();
    ok($stats['despawned'] >= 1, 'despawn command sent for removed entity, got ' . $stats['despawned']);
    ok($stats['stale'] >= 1, 'one stale result for the removed entity was dropped, got ' . $stats['stale']);
});

exit(runTests());

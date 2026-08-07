<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\component\VelocityComponent;
use pocketmine\core\ecs\EntityBuilder;

/**
 * Phase 9.4b - cross-region entity migration.
 *
 * A 2-region kernel splits the world into X columns: region 0 owns chunks
 * [-1000, 0] (block x < 16), region 1 owns chunks [1, 1000]. An entity whose
 * chunk changes owners must be migrated: removed from the old region's worker
 * store and handed to the new region's migration queue - without losing or
 * doubling any integration step.
 */

$kernel = \pocketmine\bootstrap(2);
$world = $kernel->getWorld();
$kernel->setRegionPipelineEnabled(true);
$kernel->setRegionPipelineApplyMode(true);

// Eastbound: starts in region 0 (x=15.9, chunk 0), crosses into region 1 at
// x=16 (chunk 1) after one tick. Westbound: mirrors the crossing back.
$east = $world->spawn(
    (new EntityBuilder())->at(15.9, 64.0, 0.0)->with(new VelocityComponent(2.0, 0.0, 0.0))
);
$west = $world->spawn(
    (new EntityBuilder())->at(16.1, 64.0, 8.0)->with(new VelocityComponent(-2.0, 0.0, 0.0))
);

test('migration: entities crossing region boundaries stay exact', function () use ($kernel, $east, $west) {
    $kernel->run(5);
    $stats = $kernel->getRegionPipelineStats();

    ok($stats['migrated'] >= 2, 'both entities migrated across the boundary, got ' . $stats['migrated']);
    same(0, $stats['mismatches'], 'zero gate mismatches across migration');
    same(0, $stats['lagged'], 'zero lagged results across migration');

    // No lost or doubled ticks at the boundary: positions are exactly
    // 5 ticks of v*dt past spawn, even though both entities changed regions.
    $pe = $east->getPosition();
    near(15.9 + 2.0 * 0.05 * 5, $pe->x, 1e-6, 'eastbound entity advanced exactly across the boundary');
    $pw = $west->getPosition();
    near(16.1 - 2.0 * 0.05 * 5, $pw->x, 1e-6, 'westbound entity advanced exactly across the boundary');

    // Both ends of the world must still be owned by their correct regions.
    $regions = $kernel->getRegionThreads();
    same(2, count($regions), 'two region threads exist');
    ok($regions[0]->ownsChunk(0, 0), 'region 0 owns chunk (0,0)');
    ok($regions[1]->ownsChunk(1, 0), 'region 1 owns chunk (1,0)');
});

exit(runTests());

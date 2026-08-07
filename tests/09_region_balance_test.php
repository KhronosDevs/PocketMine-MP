<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\component\VelocityComponent;
use pocketmine\core\ecs\EntityBuilder;

/**
 * Phase 9.4b - dynamic region load balancing.
 *
 * (1) A single region over the entity threshold splits into columns at the
 *     entity-weighted median, repeatedly, until every region is under the
 *     threshold - and the diff-only mirror + migration path keeps every
 *     entity bit-exact through the splits (0 mismatches, 0 lagged).
 * (2) Apply mode stays exact across splits (worker authoritative).
 * (3) When entities leave, nearly-empty regions merge back into their
 *     neighbor and the region count drops.
 */

// --- 1. Gate mode: splits at scale, exactness through splits ---------------
test('region balance: over-threshold region splits into balanced columns, gate stays exact', function () {
    $kernel = \pocketmine\bootstrap(1, 500);
    $world = $kernel->getWorld();
    $kernel->setRegionPipelineEnabled(true);

    $count = 1500;
    $spawned = [];
    for ($i = 0; $i < $count; $i++) {
        $spawned[] = $world->spawn(
            (new EntityBuilder())
                ->at(-768.0 + ($i % 50) * 32.0, 64.0, -32.0 + intdiv($i, 50) * 2.0)
                ->with(new VelocityComponent(0.5, 0.0, 0.3))
        );
    }

    $kernel->run(15);
    $stats = $kernel->getRegionPipelineStats();

    ok($stats['splits'] >= 2, 'regions split when over the threshold, got ' . $stats['splits']);
    $regionCount = count($kernel->getRegionThreads());
    ok($regionCount >= 4, 'world split into multiple balanced regions, got ' . $regionCount);
    same(0, $stats['mismatches'], 'zero gate mismatches through dynamic splits');
    same(0, $stats['lagged'], 'zero lagged results through dynamic splits');
    ok($stats['migrated'] >= $count / 2, 'split-boundary entities migrated, got ' . $stats['migrated']);

    // Exact deterministic integration over 15 ticks. Velocity is in
    // blocks/second, so per tick dx = vx * dt (dt = 0.05): 15 ticks at
    // vx = 0.5 => +0.375. Gravity accumulates to 0.08*dt^2*(0+..+14) = 0.021.
    foreach ([0, 1, 300, 999, 1499] as $i) {
        $pos = $spawned[$i]->getPosition();
        near(-768.0 + ($i % 50) * 32.0 + 0.5 * 0.05 * 15, $pos->x, 1e-6, "entity $i x after 15 ticks");
        near(-32.0 + intdiv($i, 50) * 2.0 + 0.3 * 0.05 * 15, $pos->z, 1e-6, "entity $i z after 15 ticks");
        near(64.0 - 0.021, $pos->y, 1e-6, "entity $i y with gravity over 15 ticks");
    }
    $vel = $spawned[0]->getVelocity();
    near(-0.004 * 15, $vel->y, 1e-9, 'gravity accumulated to -0.06 over 15 ticks');
});

// --- 2. Apply mode: worker authoritative stays exact across splits ---------
test('region balance: apply mode stays exact across dynamic splits', function () {
    $kernel = \pocketmine\bootstrap(1, 400);
    $world = $kernel->getWorld();
    $kernel->setRegionPipelineEnabled(true);
    $kernel->setRegionPipelineApplyMode(true);

    $count = 1200;
    $spawned = [];
    for ($i = 0; $i < $count; $i++) {
        $spawned[] = $world->spawn(
            (new EntityBuilder())
                ->at(-768.0 + ($i % 40) * 40.0, 70.0, -40.0 + intdiv($i, 40) * 2.0)
                ->with(new VelocityComponent(0.4, 0.0, 0.6))
        );
    }

    $kernel->run(12);
    $stats = $kernel->getRegionPipelineStats();

    ok($stats['splits'] >= 2, 'apply mode split regions, got ' . $stats['splits']);
    ok(count($kernel->getRegionThreads()) >= 3, 'multiple regions in apply mode, got ' . count($kernel->getRegionThreads()));
    same(0, $stats['lagged'], 'zero lagged in apply mode through splits');
    same($count * 12, $stats['applied'], 'every worker result applied through splits, got ' . $stats['applied']);

    // Worker-authoritative integration over 12 ticks (dt = 0.05).
    foreach ([0, 1, 500, 1199] as $i) {
        $pos = $spawned[$i]->getPosition();
        near(-768.0 + ($i % 40) * 40.0 + 0.4 * 0.05 * 12, $pos->x, 1e-6, "apply entity $i x after 12 ticks");
        near(-40.0 + intdiv($i, 40) * 2.0 + 0.6 * 0.05 * 12, $pos->z, 1e-6, "apply entity $i z after 12 ticks");
        near(70.0 - 0.08 * 0.0025 * 66, $pos->y, 1e-6, "apply entity $i y with gravity over 12 ticks");
    }
});

// --- 3. Merge: idle regions fold back when entities leave ------------------
test('region balance: nearly-empty regions merge back and region count drops', function () {
    $kernel = \pocketmine\bootstrap(1, 400);
    $world = $kernel->getWorld();
    $kernel->setRegionPipelineEnabled(true);
    $kernel->setAutoShutdownOnRun(false); // two run() calls below

    $count = 1200;
    for ($i = 0; $i < $count; $i++) {
        $world->spawn(
            (new EntityBuilder())
                ->at(-768.0 + ($i % 40) * 40.0, 64.0, -40.0 + intdiv($i, 40) * 2.0)
                ->with(new VelocityComponent(0.5, 0.0, 0.3))
        );
    }
    $kernel->run(6); // splits into ~4 regions
    $afterSplit = count($kernel->getRegionThreads());
    ok($afterSplit >= 3, 'split into multiple regions, got ' . $afterSplit);

    // Despawn most entities, leaving 40.
    $entities = $world->getEntities();
    $keep = 0;
    foreach ($entities as $entity) {
        if ($keep >= 40) {
            $world->despawn($entity);
        } else {
            $keep++;
        }
    }

    $kernel->run(20);
    $stats = $kernel->getRegionPipelineStats();
    $afterMerge = count($kernel->getRegionThreads());

    ok($stats['merges'] >= 1, 'idle regions merged, got ' . $stats['merges']);
    ok($afterMerge < $afterSplit, 'region count dropped after merge, got ' . $afterSplit . ' -> ' . $afterMerge);
    same(0, $stats['mismatches'], 'zero mismatches across merge transitions');
    $kernel->shutdown();
});

exit(runTests());

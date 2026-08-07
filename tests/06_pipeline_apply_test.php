<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\ecs\EntityBuilder;

/**
 * Phase 9 - region pipeline, apply mode.
 *
 * The worker is authoritative for movement+gravity: main-thread MovementSystem
 * and PhysicsSystem are disabled, the worker integrates the mirrored snapshots,
 * and results are written back. Entity positions must match the exact expected
 * integration (P0 + V * dt per tick, gravity -0.08*dt per tick).
 */

$kernel = \pocketmine\bootstrap();
$world = $kernel->getWorld();

$kernel->setRegionPipelineEnabled(true);
$kernel->setRegionPipelineApplyMode(true);
ok($kernel->isRegionPipelineApplyMode(), 'apply mode flag');

$count = 30;
$refs = [];
for ($i = 0; $i < $count; $i++) {
    $refs[] = $world->spawn(
        (new EntityBuilder())
            ->at($i * 2.0, 64.0, 0.0)
            ->with(new \pocketmine\core\component\VelocityComponent(1.0, 0.0, 0.0))
    );
}

test('apply mode: worker integrates movement + gravity exactly', function () use ($kernel, $refs) {
    $kernel->run(5);

    $stats = $kernel->getRegionPipelineStats();
    ok($stats['applied'] >= 30, 'worker results applied, got ' . $stats['applied']);
    same(0, $stats['mismatches'], 'no gate mismatches in apply mode');

    foreach ($refs as $i => $ref) {
        $pos = $ref->getPosition();
        near($i * 2.0 + 0.25, $pos->x, 1e-6, "entity $i x from worker integration");
        near(0.0, $pos->z, 1e-6, "entity $i z unchanged (no z velocity)");
    }

    // Gravity accumulated on the worker side: -0.08 * 0.05 per tick.
    $vel = $refs[0]->getVelocity();
    near(-0.02, $vel->y, 1e-9, 'worker applied gravity 5 times');
});

exit(runTests());

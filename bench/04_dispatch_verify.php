#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Verify the parallel dispatch path (>=16 entities) integrates correctly. */
require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../tests/helpers.php';

use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\ecs\EntityBuilder;

putenv('KHRONOS_FAST_TICKS=1');
$kernel = \pocketmine\bootstrap();
$kernel->setAutoShutdownOnRun(false);
$cfg = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ServerConfig::class);
if ($cfg instanceof \pocketmine\core\resource\ServerConfig) {
    $cfg->spawnMobs = false;
    $cfg->spawnAnimals = false;
}
$world = $kernel->getWorld();

$count = 300;
$refs = [];
for ($i = 0; $i < $count; $i++) {
    $refs[] = $world->spawn(
        (new EntityBuilder())
            ->at(($i % 20) * 1.5, 100.0 + ($i % 5), intdiv($i, 20) * 1.5)
            ->with(new VelocityComponent(0.5, 0.0, 0.3))
    );
}
for ($i = 0; $i < 30; $i++) {
    $world->tick(0.05);
}

$ok = true;
$moved = 0;
foreach ($refs as $i => $r) {
    $pos = $r->getPosition();
    $vel = $r->getVelocity();
    // after 30 ticks, x advanced 0.5*1.5=0.75 blk, vy = -1.6*1.5 = -2.4 (no ground hit, y=100)
    $startX = ($i % 20) * 1.5;
    $dx = $pos->x - $startX;
    $expectX = 0.5 * 1.5;
    $expectVy = -1.6 * 1.5;
    if (abs($dx - $expectX) > 1e-6 || abs($vel->y - $expectVy) > 1e-6) {
        $ok = false;
        printf("MISMATCH idx=%d dx=%.6f (expect %.6f) vy=%.6f (expect %.6f)\n",
            $i, $dx, $expectX, $vel->y, $expectVy);
    } else {
        $moved++;
    }
}

$sched = $world->getSystemScheduler();
printf("pool path: %d/%d entities integrated correctly\n", $moved, $count);
printf("%s\n", $ok ? 'PASS' : 'FAIL');
$kernel->shutdown();
exit($ok ? 0 : 1);
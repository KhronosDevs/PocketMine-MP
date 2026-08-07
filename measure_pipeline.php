#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Phase 9 region-pipeline benchmark.
 *
 * Measures main-thread tick work with a moving-entity workload under three
 * modes:
 *   off   - no pipeline (baseline; MovementSystem + PhysicsSystem on main)
 *   gate  - pipeline mirrors + compares worker results (main still simulates)
 *   apply - pipeline is authoritative for movement+gravity (main systems off)
 *
 * Usage: bin/php7/bin/php measure_pipeline.php [mode] [entities] [ticks]
 *   mode     off|gate|apply   (default: off)
 *   entities number of moving entities (default: 1000)
 *   ticks    number of ticks to run (default: 200)
 */

require_once __DIR__ . '/autoload.php';

use pocketmine\core\component\VelocityComponent;
use pocketmine\core\ecs\EntityBuilder;

$mode = $argv[1] ?? 'off';
$entities = (int)($argv[2] ?? 1000);
$ticks = (int)($argv[3] ?? 200);

$kernel = \pocketmine\bootstrap();
if ($mode !== 'off') {
    $kernel->setRegionPipelineEnabled(true);
    if ($mode === 'apply') {
        $kernel->setRegionPipelineApplyMode(true);
    }
}

// Spread moving entities across the (single) region.
$world = $kernel->getWorld();
for ($i = 0; $i < $entities; $i++) {
    $world->spawn(
        (new EntityBuilder())
            ->at(($i % 100) * 1.5, 64.0 + ($i % 8), (int)($i / 100) * 1.5)
            ->with(new VelocityComponent(0.5, 0.0, 0.3))
    );
}

echo "mode={$mode} entities={$entities} ticks={$ticks}\n";
$kernel->run($ticks);

$stats = $kernel->getTickStats();
echo json_encode([
    'mode' => $mode,
    'entities' => $entities,
    'ticks' => $ticks,
    'stats' => $stats,
    'pipeline' => $kernel->getRegionPipelineStats(),
], JSON_PRETTY_PRINT) . "\n";

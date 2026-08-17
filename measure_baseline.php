#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/autoload.php';

$kernel = \pocketmine\bootstrap();
$kernel->setAutoShutdownOnRun(false);

// Run warmup ticks
echo "Warming up...\n";
$kernel->run(100);

// Run measurement ticks
echo "Measuring baseline (1000 ticks)...\n";
$kernel->run(1000);

$stats = $kernel->getTickStats();

echo "\n=== TICK PROFILING BASELINE ===\n";
echo "Count:      {$stats['count']}\n";
echo "Mean:       {$stats['mean_ms']} ms\n";
echo "Median:     {$stats['median_ms']} ms\n";
echo "P95:        {$stats['p95_ms']} ms\n";
echo "P99:        {$stats['p99_ms']} ms\n";
echo "Min:        {$stats['min_ms']} ms\n";
echo "Max:        {$stats['max_ms']} ms\n";
echo "StdDev:     {$stats['stddev_ms']} ms\n";

$output = [
    'timestamp' => date('c'),
    'php_version' => PHP_VERSION,
    'commit' => trim(shell_exec('git rev-parse HEAD')),
    'branch' => trim(shell_exec('git branch --show-current')),
    'stats' => $stats,
];

file_put_contents(__DIR__ . '/docs/BASELINE.md', json_encode($output, JSON_PRETTY_PRINT));
echo "\nBaseline saved to docs/BASELINE.md\n";
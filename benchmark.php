#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Benchmark profiling script for PocketMine-MP optimization
 * Run with: bin/php7/bin/php benchmark.php
 */

declare(strict_types=1);

require_once __DIR__ . '/autoload.php';

use pocketmine\Kernel;

echo "=== PocketMine-MP Optimization Benchmark ===\n\n";

// Create kernel
$kernel = \pocketmine\bootstrap();

// Warmup
echo "Warming up (100 ticks)...\n";
$kernel->run(100);

// Benchmark runs
$runs = [
    ['name' => 'Empty world', 'ticks' => 1000],
    ['name' => 'Light load', 'ticks' => 1000],
];

foreach ($runs as $run) {
    echo "\nRunning {$run['name']} benchmark ({$run['ticks']} ticks)...\n";
    
    // Reset stats
    $reflection = new ReflectionClass($kernel);
    $tickDurationsProperty = $reflection->getProperty('tickDurations');
    $tickDurationsProperty->setAccessible(true);
    $tickDurationsProperty->setValue($kernel, []);
    
    // Run benchmark
    $startTime = hrtime(true);
    $kernel->run($run['ticks']);
    $endTime = hrtime(true);
    
    $stats = $kernel->getTickStats();
    
    echo "  Total time: " . round(($endTime - $startTime) / 1_000_000_000, 3) . "s\n";
    echo "  Mean tick:  {$stats['mean_ms']} ms\n";
    echo "  Median:     {$stats['median_ms']} ms\n";
    echo "  P95:        {$stats['p95_ms']} ms\n";
    echo "  P99:        {$stats['p99_ms']} ms\n";
    echo "  StdDev:     {$stats['stddev_ms']} ms\n";
    echo "  Min/Max:    {$stats['min_ms']} / {$stats['max_ms']} ms\n";
    
    // Memory
    $mem = memory_get_usage(true);
    $memPeak = memory_get_peak_usage(true);
    echo "  Memory:     " . round($mem / 1024 / 1024, 2) . " MB (peak: " . round($memPeak / 1024 / 1024, 2) . " MB)\n";
    
    // Entity pool stats
    if (class_exists(\pocketmine\domain\ecs\Entity::class)) {
        $stats = \pocketmine\domain\ecs\Entity::getPoolStats();
        echo "  Entity pool: {$stats['size']} / {$stats['max']}\n";
    }
    
    // Query cache stats
    if (class_exists(\pocketmine\domain\ecs\QueryBuilder::class)) {
        $reflection = new ReflectionClass(\pocketmine\domain\ecs\QueryBuilder::class);
        $cacheProperty = $reflection->getProperty('queryCache');
        $cacheProperty->setAccessible(true);
        $cache = $cacheProperty->getValue();
        echo "  Query cache: " . count($cache) . " entries\n";
    }
}

echo "\n=== Benchmark Complete ===\n";

// Save results
$results = [
    'timestamp' => date('c'),
    'php_version' => PHP_VERSION,
    'commit' => trim(shell_exec('git rev-parse HEAD')),
    'branch' => trim(shell_exec('git branch --show-current')),
    // Stats would be added here
];

file_put_contents(__DIR__ . '/docs/BENCHMARK.md', json_encode($results, JSON_PRETTY_PRINT));
echo "\nResults saved to docs/BENCHMARK.md\n";
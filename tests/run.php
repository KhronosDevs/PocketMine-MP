#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Test runner: executes every tests/*_test.php in a separate PHP process
 * (static ECS state is process-scoped) and reports a per-file summary.
 *
 * Usage: bin/php7/bin/php tests/run.php
 */

$files = glob(__DIR__ . '/*_test.php');
sort($files);
if (empty($files)) {
    fwrite(STDERR, "No test files found in tests/\n");
    exit(1);
}

$failedFiles = 0;
foreach ($files as $file) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file);
    exec($cmd . ' 2>&1', $output, $code);
    echo "=== " . basename($file) . " ===\n";
    // Filter harmless module-reload noise from the bundled PHP binary.
    $noise = fn(string $l): bool => preg_match('/^(PHP Warning:  Module |Cannot load Zend)/', $l) === 1;
    echo implode("\n", array_values(array_filter($output, fn(string $l) => !$noise($l)))) . "\n";
    if ($code !== 0) {
        $failedFiles++;
    }
    $output = [];
}

echo "\n" . ($failedFiles === 0 ? 'ALL TEST FILES PASSED' : $failedFiles . ' TEST FILE(S) FAILED') . "\n";
exit($failedFiles === 0 ? 0 : 1);

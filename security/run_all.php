<?php

declare(strict_types=1);

/**
 * Run every security/ script in sequence and aggregate the results.
 *
 * Usage:
 *   bin/php7/bin/php security/run_all.php
 *
 * Each script boots its own throwaway server on a random loopback port.
 * Exit code is non-zero if any script fails.
 */

$scripts = [
    'hostile_nbt_test.php',     // in-process NBT bombs (fast, no server)
    'hostile_raklib_test.php',  // live offline-layer floods & malformed datagrams
    'hostile_game_test.php',    // live authenticated hostile input
    'flood_test.php',           // live DoS response verification
];

$root = dirname(__DIR__);
$php = PHP_BINARY;
$failures = 0;

echo "=== Khronos security suite ===" . PHP_EOL;
foreach ($scripts as $script) {
    echo PHP_EOL . '>>> ' . $script . PHP_EOL;
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/' . $script) . ' 2>&1';
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    foreach ($output as $line) {
        // Keep the report readable: PASS/FAIL lines and the summary.
        if (str_starts_with($line, 'PASS') || str_starts_with($line, 'FAIL') || str_starts_with($line, '[security]')) {
            echo '    ' . $line . PHP_EOL;
        }
    }
    if ($code !== 0) {
        ++$failures;
        echo '    >>> ' . $script . ' exited with code ' . $code . PHP_EOL;
    }
}

echo PHP_EOL;
if ($failures === 0) {
    echo 'ALL SECURITY SCRIPTS PASSED' . PHP_EOL;
    exit(0);
}
echo $failures . ' SECURITY SCRIPT(S) FAILED' . PHP_EOL;
exit(1);

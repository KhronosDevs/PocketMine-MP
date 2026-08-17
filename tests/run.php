#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Test runner: executes every tests/*_test.php in a separate PHP process
 * (static ECS state is process-scoped) and reports a per-file summary.
 *
 * Usage:
 *   bin/php7/bin/php tests/run.php            # serial (as before)
 *   bin/php7/bin/php tests/run.php -j 4       # parallel, up to 4 files at once
 *   bin/php7/bin/php tests/run.php --filter=x # pass --filter through to each file
 *
 * Speedups over the naive loop:
 *  - Every child runs with KHRONOS_FAST_TICKS=1, so Kernel::run() skips the
 *    50ms-per-tick 20 TPS pacing sleep. That sleep was the dominant cost of
 *    the suite (tests pump run(1) hundreds of times and nothing in a test
 *    needs real-time pacing).
 *  - With -j N, files run in parallel. Each child chdir()s into its own
 *    temp dir (sys_get_temp_dir()/khr_tests/<file>), so kernel dataPath,
 *    world region files, and every getcwd()-relative path in the tests stay
 *    isolated per worker - and the suite no longer touches the repo's real
 *    worlds/ directory at all.
 */

$jobs = 1;
$filterArg = null;
$args = $argv ?? [];
for ($i = 1; $i < count($args); $i++) {
    if ($args[$i] === '-j' && isset($args[$i + 1])) {
        $jobs = max(1, (int) $args[++$i]);
    } elseif (str_starts_with($args[$i], '-j')) {
        $jobs = max(1, (int) substr($args[$i], 2));
    } elseif (str_starts_with($args[$i], '--filter=')) {
        $filterArg = $args[$i];
    } elseif ($args[$i] === '--filter' && isset($args[$i + 1])) {
        $filterArg = $args[$i] . ' ' . $args[++$i];
    }
}

$files = glob(__DIR__ . '/*_test.php');
sort($files);
if (empty($files)) {
    fwrite(STDERR, "No test files found in tests/\n");
    exit(1);
}

$noise = fn(string $l): bool => preg_match('/^(PHP Warning:  Module |Cannot load Zend)/', $l) === 1;
$filterNoise = static function (string $out): string {
    // Keep every line EXCEPT the bundled-PHP module-reload warnings.
    return implode("\n", array_values(array_filter(
        explode("\n", $out),
        fn(string $l): bool => preg_match('/^(PHP Warning:  Module |Cannot load Zend)/', $l) !== 1
    )));
};

$start = microtime(true);
$failedFiles = 0;

if ($jobs <= 1) {
    // Serial mode: exactly the original behavior, plus fast ticks.
    foreach ($files as $file) {
        $cmd = 'KHRONOS_FAST_TICKS=1 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file)
            . ($filterArg !== null ? ' ' . $filterArg : '');
        exec($cmd . ' 2>&1', $output, $code);
        echo "=== " . basename($file) . " ===\n";
        echo implode("\n", array_values(array_filter($output, fn(string $l) => !$noise($l)))) . "\n";
        if ($code !== 0) {
            $failedFiles++;
        }
        $output = [];
    }
} else {
    // Parallel mode: up to $jobs children at once, each in its own temp cwd.
    // Output is written to a file per worker (no pipes -> no pipe-buffer
    // deadlocks while other workers are still running).
    /** @var array<int, array{proc: resource, file: string, name: string, done: bool, code: int}> $running */
    $running = [];
    $queue = $files;
    $done = []; // name => [output, code]
    $slot = fn(string $file): string => sys_get_temp_dir() . '/khr_tests/' . basename($file, '.php');

    while ($queue !== [] || $running !== []) {
        // Fill free slots.
        while ($queue !== [] && count($running) < $jobs) {
            $file = array_shift($queue);
            $workDir = $slot($file);
            if (!is_dir($workDir)) {
                @mkdir($workDir, 0777, true);
            }
            // Clean stale state from a previous run so persistence tests see
            // a fresh world (they used to rm -rf the repo's worlds/).
            foreach (glob($workDir . '/*') ?: [] as $f) {
                if (is_dir($f)) {
                    exec('rm -rf ' . escapeshellarg($f));
                } else {
                    @unlink($f);
                }
            }
            $outFile = $workDir . '/_output.txt';
            $cmd = 'KHRONOS_FAST_TICKS=1 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file)
                . ($filterArg !== null ? ' ' . $filterArg : '')
                . ' > ' . escapeshellarg($outFile) . ' 2>&1';
            $proc = proc_open($cmd, [], $pipes, $workDir);
            if (is_resource($proc)) {
                $running[] = ['proc' => $proc, 'file' => $outFile, 'name' => basename($file), 'done' => false, 'code' => -1];
            } else {
                fwrite(STDERR, "failed to start " . basename($file) . "\n");
                $failedFiles++;
            }
        }
        // Reap finished workers. proc_get_status() reports the real exit
        // code on the FIRST call that sees running=false; a later call
        // returns -1 (the status is consumed), so capture it in that same
        // call and use proc_close() only to release the resource.
        foreach ($running as $i => $r) {
            $status = proc_get_status($r['proc']);
            if (!$status['running']) {
                $code = $status['exitcode'];
                $closed = proc_close($r['proc']);
                if ($code === -1 && $closed !== -1) {
                    $code = $closed;
                }
                $out = is_file($r['file']) ? (string) file_get_contents($r['file']) : '';
                $done[$r['name']] = ['output' => $filterNoise($out), 'code' => $code];
                fwrite(STDOUT, '[' . date('H:i:s') . '] done: ' . $r['name'] . ' (exit ' . $code . ')' . PHP_EOL);
                if ($code !== 0) {
                    $failedFiles++;
                }
                unset($running[$i]);
            }
        }
        if ($running !== []) {
            usleep(10_000);
        }
    }

    foreach ($files as $file) {
        $name = basename($file);
        if (!isset($done[$name])) {
            continue;
        }
        echo "=== $name ===\n" . $done[$name]['output'] . "\n";
    }
}

$elapsed = round(microtime(true) - $start, 1);
echo "\n" . ($failedFiles === 0 ? 'ALL TEST FILES PASSED' : $failedFiles . ' TEST FILE(S) FAILED') . " in {$elapsed}s\n";
exit($failedFiles === 0 ? 0 : 1);

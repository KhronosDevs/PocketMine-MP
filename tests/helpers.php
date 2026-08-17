<?php

declare(strict_types=1);

/**
 * Minimal, zero-dependency test framework.
 *
 * Each tests/*_test.php file requires this helper, registers its cases with
 * test(), then exits with runTests()'s exit code. tests/run.php executes
 * every *_test.php in a separate PHP process so static ECS state
 * (Entity::nextId, EntityRef cache, query cache) never leaks between files.
 */

/** @var list<array{name: string, fn: callable}> */
$GLOBALS['__tests'] = [];
$GLOBALS['__assertions'] = 0;

function test(string $name, callable $fn): void {
    $GLOBALS['__tests'][] = ['name' => $name, 'fn' => $fn];
}

function ok(bool $cond, string $msg = ''): void {
    $GLOBALS['__assertions']++;
    if (!$cond) {
        throw new RuntimeException('assertion failed: ' . $msg);
    }
}

function same(mixed $expected, mixed $actual, string $msg = ''): void {
    ok(
        $expected === $actual,
        $msg . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'
    );
}

function near(float $expected, float $actual, float $eps = 1e-9, string $msg = ''): void {
    ok(
        abs($expected - $actual) <= $eps,
        $msg . ' (expected ' . $expected . ' ±' . $eps . ', got ' . $actual . ')'
    );
}

function throws(callable $fn, string $class, string $msg = ''): void {
    try {
        $fn();
    } catch (\Throwable $e) {
        ok($e instanceof $class, $msg . ' (threw ' . get_class($e) . ', expected ' . $class . ')');
        return;
    }
    ok(false, $msg . ' (expected exception ' . $class . ' was not thrown)');
}

function runTests(): int {
    // Optional --filter=substring (or --filter substring): run only the test
    // cases whose name contains it. Lets a dev iterate on one test in a big
    // file (e.g. tests/17) instead of running all of its cases.
    $filter = null;
    $argv = $_SERVER['argv'] ?? [];
    for ($i = 0; $i < count($argv); $i++) {
        if (str_starts_with($argv[$i], '--filter=')) {
            $filter = substr($argv[$i], strlen('--filter='));
        } elseif ($argv[$i] === '--filter' && isset($argv[$i + 1])) {
            $filter = $argv[$i + 1];
            $i++;
        }
    }

    $failures = 0;
    $run = 0;
    foreach ($GLOBALS['__tests'] as $t) {
        if ($filter !== null && !str_contains($t['name'], $filter)) {
            continue;
        }
        $run++;
        try {
            ($t['fn'])();
            fwrite(STDOUT, "  ok - {$t['name']}\n");
        } catch (\Throwable $e) {
            $failures++;
            fwrite(STDOUT, "  FAIL - {$t['name']}\n         " . $e->getMessage() . "\n         at " . $e->getFile() . ':' . $e->getLine() . "\n");
        }
    }
    if ($filter !== null) {
        fwrite(STDOUT, "\n{$run} test(s) matched --filter=$filter, {$GLOBALS['__assertions']} assertion(s), {$failures} failure(s)\n");
    } else {
        $count = count($GLOBALS['__tests']);
        fwrite(STDOUT, "\n{$count} test(s), {$GLOBALS['__assertions']} assertion(s), {$failures} failure(s)\n");
    }
    return $failures === 0 ? 0 : 1;
}

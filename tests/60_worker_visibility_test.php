<?php
declare(strict_types=1);
require __DIR__ . '/../autoload.php';

use pocketmine\adapter\driven\threading\PmmpThreadPool;
use pocketmine\adapter\driven\threading\PluginTask;

require __DIR__ . '/helpers.php';

use pocketmine\adapter\driven\threading\PluginFuture;

/**
 * Empirical check for the report:
 *   "pmmpthread workers only see classes that existed at thread creation,
 *    so plugin-defined Runnables fatal with class-not-found in workers."
 *
 * Two scenarios:
 *   A) A task class loaded BEFORE the Pool was created (the core case).
 *   B) A task class defined AFTER the Pool was created (a plugin whose
 *      autoloader registers post-boot — the reported broken case).
 *
 * Scenario B documents actual behavior rather than asserting a specific
 * outcome: whether the late class completes, rejects, or hangs tells us
 * if the classloader gap is real in this PHP build.
 */

/** Core-defined task: file-level class, loaded before any Pool exists. */
final class WorkerVisibilityEarlyTask extends PluginTask {
	public function run(): void {
		$this->complete('early-ok');
	}
}

/** Poll a PluginFuture with a hard timeout so a hung worker cannot stall the suite. */
function waitForFutureDone(PluginFuture $f, int $timeoutMs = 5000): bool {
	$start = microtime(true);
	while (!$f->isDone()) {
		if ((microtime(true) - $start) * 1000 > $timeoutMs) {
			return false;
		}
		usleep(10_000);
	}
	return true;
}

$hasPthreads = class_exists(\pmmp\thread\Pool::class);

if ($hasPthreads) {

	test('A: worker runs a task class loaded BEFORE pool creation', function (): void {
		$pool = new PmmpThreadPool(2);
		try {
			$f = $pool->submitPluginTask(new WorkerVisibilityEarlyTask());
			ok(waitForFutureDone($f), 'early-class future resolved within timeout');
			ok(!$f->isCancelled(), 'not cancelled');
			same('early-ok', $f->getResult(), 'early-defined task executed on worker');
		} finally {
			$pool->shutdown();
		}
	});

	test('B: worker visibility of a class defined AFTER pool creation', function (): void {
		$pool = new PmmpThreadPool(2);

		// Simulate a plugin autoloader registering its classes after boot.
		$lateClass = 'WorkerVisibilityLateTask' . bin2hex(random_bytes(3));
		eval(sprintf(
			'final class %s extends \pocketmine\adapter\driven\threading\PluginTask {' .
			' public function run(): void { $this->complete("late-ok"); } }',
			$lateClass
		));
		ok(class_exists($lateClass), 'late class exists on main thread');

		try {
			$f = $pool->submitPluginTask(new $lateClass());
			$done = waitForFutureDone($f, 5000);
			if ($done && !$f->isCancelled()) {
				try {
					$result = $f->getResult();
					fwrite(STDOUT, "    [finding] LATE-DEFINED CLASS RAN OK (result=" .
						var_export($result, true) . ") — classloader gap NOT reproduced\n");
					ok(true);
				} catch (\Throwable $e) {
					fwrite(STDOUT, "    [finding] LATE-DEFINED CLASS FAILED ON WORKER: " .
						$e->getMessage() . " — classloader gap CONFIRMED\n");
					// Documented behavior, not a failure of this build's contract.
				}
			} else {
				fwrite(STDOUT, "    [finding] LATE-DEFINED TASK NEVER COMPLETED (worker fatal?) " .
					"— classloader gap CONFIRMED (hung/fataled)\n");
			}
		} finally {
			$pool->shutdown();
		}
	});

} else {
	test('pmmpthread extension present', function () use ($hasPthreads): void {
		ok($hasPthreads, 'pmmpthread Pool class not available in this PHP build');
	});
}

exit(runTests());

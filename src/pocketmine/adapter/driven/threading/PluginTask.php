<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\threading;

use pmmp\thread\Runnable;

/**
 * Base class for plugin tasks that run on real worker threads.
 *
 * Extend this instead of Runnable to get built-in future resolution:
 *   - complete($result)  → resolves the async future with a value
 *   - fail($error)       → rejects the async future with an error
 *
 * If run() completes without calling complete() or fail(), the future
 * is automatically resolved with null. If run() throws, the future
 * is rejected with the exception.
 *
 * Properties on this class are ThreadSafe (pmmpthread) and shared
 * between the worker and main thread. Use them to pass input data in
 * and results out.
 *
 * IMPORTANT: This class and all subclasses MUST be loaded on the main
 * thread before pool creation (pmmpthread workers cannot autoload).
 * Use class_exists(MyTask::class) in your plugin's onEnable().
 *
 * Usage:
 *   class MyHeavyTask extends PluginTask {
 *       public string $input = '';
 *       public string $result = '';
 *
 *       public function run(): void {
 *           $this->result = expensiveCompute($this->input);
 *           $this->complete($this->result);
 *       }
 *   }
 *
 *   $task = new MyHeavyTask();
 *   $task->input = "data";
 *   $future = $this->getThreadingPort()->submitPluginTask($task);
 *   $future->then(
 *       fn($result) => $this->logger->info("Done: $result"),
 *       fn($error) => $this->logger->error("Failed: $error")
 *   );
 */
abstract class PluginTask extends Runnable {
    private ?FutureImpl $future = null;

    /**
     * Set the future that this task should resolve when done.
     * Called automatically by submitPluginTask() before dispatch.
     *
     * @internal Do not call from plugin code.
     */
    final public function setFuture(FutureImpl $future): void {
        $this->future = $future;
    }

    /**
     * Resolve the async future with a result value.
     * Call this from run() when the task has produced a result.
     */
    final protected function complete(mixed $result = null): void {
        $this->future?->resolve($result);
    }

    /**
     * Reject the async future with an error.
     * Call this from run() when the task has failed.
     */
    final protected function fail(\Throwable $e): void {
        $this->future?->reject($e);
    }
}

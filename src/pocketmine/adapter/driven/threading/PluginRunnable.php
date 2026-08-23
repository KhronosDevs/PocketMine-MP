<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\threading;

use pmmp\thread\Runnable;

/**
 * Worker-thread wrapper that runs an arbitrary Runnable and resolves a
 * FutureImpl when done. This is the bridge between the plugin's task
 * (which knows nothing about futures) and the main-thread callback system.
 *
 * The worker calls run() → executes the inner task → resolves/rejects the
 * future → the main-thread PluginTaskManager drains it on the next tick.
 *
 * Must be pre-loaded on the main thread before pool creation (pmmpthread
 * workers cannot autoload).
 */
final class PluginRunnable extends Runnable {
    public function __construct(
        /** The user's task — any Runnable or PluginTask subclass. */
        public readonly Runnable $task,
        /** Shared future cell — ThreadSafe so the worker can write to it. */
        public readonly FutureImpl $future,
    ) {}

    public function run(): void {
        try {
            $this->task->run();
            // If the task did not explicitly resolve/reject (e.g. plain
            // Runnable without PluginTask), resolve with null as a safety net.
            if (!$this->future->isDone()) {
                $this->future->resolve(null);
            }
        } catch (\Throwable $e) {
            if (!$this->future->isDone()) {
                $this->future->reject($e);
            }
        }
    }
}

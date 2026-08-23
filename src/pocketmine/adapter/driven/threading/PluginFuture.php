<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\threading;

use pocketmine\port\driven\Future;

/**
 * Main-thread-only async future for plugin tasks running on real workers.
 *
 * Unlike FutureImpl (ThreadSafe, shared across threads), this class lives
 * exclusively on the main thread and holds closure callbacks that cannot
 * cross thread boundaries. The Kernel's PluginTaskManager drains completed
 * futures each tick and fires registered callbacks.
 *
 * Usage:
 *   $future = $threading->submitPluginTask(new MyTask(...));
 *   $future->then(
 *       fn($result) => $this->logger->info("Done: $result"),
 *       fn($error) => $this->logger->error("Failed: $error")
 *   );
 *   // Returns immediately — callback fires on next tick when worker finishes.
 */
final class PluginFuture {
    /** @var list<callable> */
    private array $successCallbacks = [];
    /** @var list<callable> */
    private array $errorCallbacks = [];
    private bool $fired = false;

    public function __construct(
        private readonly Future $inner,
    ) {}

    /**
     * Register a callback for when the task completes successfully.
     * The callback receives the result value and runs on the main thread.
     *
     * @param callable(mixed): void $onSuccess
     * @param callable(\Throwable): void|null $onError
     */
    public function then(callable $onSuccess, ?callable $onError = null): self {
        if ($this->inner->isDone()) {
            // Already done — fire immediately (rare edge case: task finished
            // between submit and then() in the same tick).
            if ($this->inner->isCancelled()) {
                return $this;
            }
            try {
                $onSuccess($this->inner->getResult());
            } catch (\Throwable $e) {
                if ($onError !== null) {
                    $onError($e);
                }
            }
            return $this;
        }
        $this->successCallbacks[] = $onSuccess;
        if ($onError !== null) {
            $this->errorCallbacks[] = $onError;
        }
        return $this;
    }

    /**
     * Check if the underlying task has finished (workers resolved/rejected).
     */
    public function isDone(): bool {
        return $this->inner->isDone();
    }

    /**
     * Check if the task was cancelled.
     */
    public function isCancelled(): bool {
        return $this->inner->isCancelled();
    }

    /**
     * Get the result. Throws if the task failed or is not done.
     * Prefer using then() callbacks instead of calling this directly.
     */
    public function getResult(): mixed {
        return $this->inner->getResult();
    }

    /**
     * Cancel the task (prevents callback firing if not yet done).
     */
    public function cancel(): bool {
        return $this->inner->cancel();
    }

    /**
     * Fire registered callbacks. Called by PluginTaskManager each tick
     * for futures whose inner FutureImpl is done.
     *
     * @internal Called by PluginTaskManager — do not call from plugin code.
     */
    public function fireCallbacks(): void {
        if ($this->fired || !$this->inner->isDone()) {
            return;
        }
        $this->fired = true;

        if ($this->inner->isCancelled()) {
            return;
        }

        try {
            $result = $this->inner->getResult();
            foreach ($this->successCallbacks as $cb) {
                try {
                    $cb($result);
                } catch (\Throwable $e) {
                    fwrite(STDERR, "[async] Error in success callback: {$e->getMessage()}" . PHP_EOL);
                }
            }
        } catch (\Throwable $e) {
            foreach ($this->errorCallbacks as $cb) {
                try {
                    $cb($e);
                } catch (\Throwable $inner) {
                    fwrite(STDERR, "[async] Error in error callback: {$inner->getMessage()}" . PHP_EOL);
                }
            }
            if ($this->errorCallbacks === []) {
                fwrite(STDERR, "[async] Unhandled async error: {$e->getMessage()}" . PHP_EOL);
            }
        }

        // Release closure references.
        $this->successCallbacks = [];
        $this->errorCallbacks = [];
    }
}

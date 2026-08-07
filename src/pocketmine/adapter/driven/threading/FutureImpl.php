<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\threading;

use pocketmine\port\driven\Future;
use pmmp\thread\ThreadSafe;

/**
 * Thread-safe future shared between the main thread and pool workers.
 *
 * pmmpthread v6 has no Threaded class; ThreadSafe provides the
 * synchronized/wait/notify primitives needed here. Results are stored
 * via json_encode when they are not thread-safe scalars (plain arrays
 * and objects cannot be assigned to ThreadSafe properties directly).
 */
final class FutureImpl extends ThreadSafe implements Future {
    private mixed $result = null;
    private ?\Throwable $error = null;
    private bool $done = false;
    private bool $cancelled = false;

    public function await(): void {
        $this->synchronized(function () {
            while (!$this->done && !$this->cancelled) {
                $this->wait();
            }
        });
    }

    public function getResult(): mixed {
        if ($this->error !== null) {
            throw $this->error;
        }
        return $this->result;
    }

    public function isDone(): bool {
        return $this->done;
    }

    public function isCancelled(): bool {
        return $this->cancelled;
    }

    public function cancel(): bool {
        if ($this->done) {
            return false;
        }
        $this->cancelled = true;
        $this->synchronized(function () {
            $this->notify();
        });
        return true;
    }

    public function resolve(mixed $result): void {
        $this->synchronized(function () use ($result) {
            $this->result = self::sanitizeResult($result);
            $this->done = true;
            $this->notify();
        });
    }

    public function reject(\Throwable $e): void {
        $this->synchronized(function () use ($e) {
            $this->error = $e;
            $this->done = true;
            $this->notify();
        });
    }

    /**
     * ThreadSafe properties may only hold thread-safe values (scalars,
     * ThreadSafe instances). Plain arrays/objects are encoded to JSON.
     */
    private static function sanitizeResult(mixed $result): mixed {
        if ($result === null || is_scalar($result) || $result instanceof ThreadSafe) {
            return $result;
        }
        return json_encode($result, JSON_UNESCAPED_SLASHES);
    }
}

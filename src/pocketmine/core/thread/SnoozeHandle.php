<?php

declare(strict_types=1);

namespace pocketmine\core\thread;

use pmmp\thread\ThreadSafe;

/**
 * A wait/notify condvar for worker threads, replacing busy-polling loops.
 *
 * Worker threads call sleep() to block until either a notification arrives
 * (via wakeup(), called by the main thread after queueing work) or the
 * timeout expires. The notification COUNT (not just a boolean) is what makes
 * this race-free: if the main thread wakes the worker while it is still
 * processing (not yet sleeping), the count is >0 when sleep() is reached, so
 * sleep() skips the wait and the worker immediately re-checks its queue
 * instead of blocking and missing the work.
 *
 * This mirrors the old-src pocketmine\snooze pattern (SleeperNotifier +
 * ThreadedSleeper) adapted to the pmmpthread v6 API: ThreadSafe subclasses
 * get synchronized()/wait()/notify() for free.
 */
final class SnoozeHandle extends ThreadSafe {
    private int $notifCount = 0;

    /**
     * Block until wakeup() is called or $timeoutUs microseconds elapse.
     * If a wakeup already happened (count > 0) the call returns immediately
     * so the caller re-drains its work queue.
     */
    public function sleep(int $timeoutUs = 0): void {
        $this->synchronized(function (int $timeoutUs): void {
            if ($this->notifCount === 0) {
                $this->wait($timeoutUs);
            }
        }, $timeoutUs);
    }

    /** Wake a sleeping worker (or mark a pending wakeup for the next sleep). */
    public function wakeup(): void {
        $this->synchronized(function (): void {
            ++$this->notifCount;
            $this->notify();
        });
    }

    /**
     * Consume any pending wakeup counts. Called by the worker after it has
     * drained its queues so the next sleep() actually blocks until fresh
     * work arrives (a wakeup consumed by the drain must not keep the worker
     * spinning).
     */
    public function consumeWakeups(): void {
        $this->synchronized(function (): void {
            $this->notifCount = 0;
        });
    }

    /** True when a wakeup is pending (worker has work to re-check). */
    public function hasWakeup(): bool {
        // read is atomic under pmmpthread's auto-locking
        return $this->notifCount > 0;
    }
}

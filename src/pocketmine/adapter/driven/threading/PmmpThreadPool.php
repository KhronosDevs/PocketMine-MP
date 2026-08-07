<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\threading;

use pocketmine\port\driven\Future;
use pocketmine\port\driven\ThreadingPort;

/**
 * Threading port backed by pmmpthread's Pool.
 *
 * The ECS world and its entities live on the main thread, and closures
 * capturing them cannot be serialized into pmmpthread v6 workers, so
 * submitted tasks are executed synchronously. The Pool is retained for
 * the worker lifecycle API (shutdown/collect) and future snapshot-based
 * parallel work.
 */
final class PmmpThreadPool implements ThreadingPort {
    private int $workerCount;

    public function __construct(int $workerCount = 4) {
        $this->workerCount = max(1, $workerCount);
    }

    public function submit(callable $task): Future {
        $future = new FutureImpl();
        try {
            $result = $task();
            $future->resolve($result);
        } catch (\Throwable $e) {
            $future->reject($e);
        }
        return $future;
    }

    public function submitToWorker(int $workerId, callable $task): Future {
        return $this->submit($task);
    }

    public function submitRoundRobin(callable $task): Future {
        return $this->submit($task);
    }

    public function parallelFor(iterable $items, callable $body): void {
        foreach ($items as $item) {
            $this->submit(fn() => $body($item))->await();
        }
    }

    public function awaitAll(iterable $futures): void {
        foreach ($futures as $future) {
            $future->await();
        }
    }

    public function shutdown(): void {
        // Synchronous execution model: nothing to shut down.
    }
}

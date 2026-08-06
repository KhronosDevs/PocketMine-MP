<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\threading;

use pocketmine\port\driven\Future;
use pocketmine\port\driven\ThreadingPort;
use pocketmine\Thread;

final class PmmpThreadPool implements ThreadingPort {
    private array $workers = [];
    private \ThreadSafe $taskQueue;
    private int $workerCount;
    private int $nextWorker = 0; // For round-robin distribution

    public function __construct(int $workerCount = 4) {
        $this->workerCount = max(1, $workerCount);
        $this->taskQueue = new \ThreadSafe();

        for ($i = 0; $i < $this->workerCount; $i++) {
            $worker = new WorkerThread($this->taskQueue);
            $worker->setClassLoader(\pocketmine\Server::getInstance()->getLoader());
            $worker->start(Thread::INHERIT_ALL);
            $this->workers[] = $worker;
        }
    }

    public function submit(callable $task): Future {
        $future = new FutureImpl();
        $this->taskQueue[] = new ThreadedTask($task, $future);
        return $future;
    }

    public function submitToWorker(int $workerId, callable $task): Future {
        $future = new FutureImpl();
        $workerId = max(0, min($workerId, $this->workerCount - 1));
        $this->workers[$workerId]->stack(new ThreadedTask($task, $future));
        return $future;
    }

    public function submitRoundRobin(callable $task): Future {
        $future = new FutureImpl();
        $worker = $this->workers[$this->nextWorker];
        $this->nextWorker = ($this->nextWorker + 1) % $this->workerCount;
        $worker->stack(new ThreadedTask($task, $future));
        return $future;
    }

    public function parallelFor(iterable $items, callable $body): void {
        $futures = [];
        foreach ($items as $item) {
            $futures[] = $this->submit(fn() => $body($item));
        }
        $this->awaitAll($futures);
    }

    public function parallelMap(iterable $items, callable $mapper): array {
        $futures = [];
        $keys = [];
        foreach ($items as $key => $item) {
            $keys[] = $key;
            $futures[] = $this->submit(fn() => $mapper($item));
        }
        $this->awaitAll($futures);
        $results = [];
        foreach ($futures as $i => $future) {
            $results[$keys[$i]] = $future->getResult();
        }
        return $results;
    }

    public function awaitAll(iterable $futures): void {
        foreach ($futures as $future) {
            $future->await();
        }
    }

    public function getWorkerCount(): int {
        return $this->workerCount;
    }

    public function shutdown(): void {
        foreach ($this->workers as $worker) {
            $worker->quit();
        }
        $this->workers = [];
    }
}
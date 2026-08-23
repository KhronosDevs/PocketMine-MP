<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\threading;

use pocketmine\port\driven\Future;
use pocketmine\port\driven\ThreadingPort;
use pmmp\thread\Pool;
use pmmp\thread\Runnable;
use pocketmine\adapter\driven\threading\PluginFuture;
use pocketmine\adapter\driven\threading\PluginRunnable;
use pocketmine\adapter\driven\threading\PluginTask;

/**
 * Threading port backed by pmmpthread's real Worker Pool.
 *
 * Two dispatch paths:
 * 1. ECS parallel systems: submit() snapshots archetype data into an
 *    EcsSystemTask (Runnable) and dispatches it to a real worker thread.
 *    The worker computes pending writes on flat arrays; the main thread
 *    merges results back into component pending buffers.
 * 2. Plugin async tasks / callables: run synchronously on the main thread
 *    (closures cannot be serialized to pmmpthread workers).
 */
final class PmmpThreadPool implements ThreadingPort {
    private Pool $pool;
    private int $workerCount;

    public function __construct(int $workerCount = 4) {
        $this->workerCount = max(1, $workerCount);
        $this->pool = new Pool($this->workerCount);
    }

    /**
     * Execute a callable synchronously (backward compat for plugins).
     * Closures capture main-thread objects and cannot be sent to workers.
     */
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

    /**
     * Submit a Runnable to a real worker thread and return a non-blocking
     * future with callback support.
     *
     * If the task is a PluginTask, its future is injected so it can call
     * complete() or fail() from within run(). For plain Runnables, the
     * future is auto-resolved when run() returns (or rejected on throw).
     */
    public function submitPluginTask(Runnable $task): PluginFuture {
        $future = new FutureImpl();
        if ($task instanceof PluginTask) {
            $task->setFuture($future);
        }
        $wrapper = new PluginRunnable($task, $future);
        $this->pool->submit($wrapper);
        $pluginFuture = new PluginFuture($future);
        // Auto-track so the Kernel drains callbacks each tick.
        $kernel = \pocketmine\Kernel::getInstance();
        if ($kernel !== null) {
            $kernel->trackPluginFuture($pluginFuture);
        }
        return $pluginFuture;
    }

    public function shutdown(): void {
        $this->pool->shutdown();
    }

    /**
     * Submit an ECS system task to a real worker thread.
     * Returns the ParallelResult cell that the worker writes into.
     * The caller must await completion before reading results.
     */
    public function submitTask(Runnable $task): void {
        $this->pool->submit($task);
    }

    /**
     * Reap finished tasks. Must be called in a loop until all results
     * are collected, same pattern as ParallelGeneratorAdapter.
     */
    public function collectTasks(callable $collector): void {
        $this->pool->collect($collector);
    }

    /**
     * Get the underlying pmmpthread Pool for direct task submission.
     * Used by SystemScheduler for ECS dispatch.
     */
    public function getPool(): Pool {
        return $this->pool;
    }
}

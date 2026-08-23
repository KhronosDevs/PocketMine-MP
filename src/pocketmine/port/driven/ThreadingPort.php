<?php

declare(strict_types=1);

namespace pocketmine\port\driven;

use pocketmine\adapter\driven\threading\PluginFuture;
use pmmp\thread\Runnable;

interface ThreadingPort {
    /**
     * Execute a callable synchronously on the main thread.
     * Closures capture main-thread objects and cannot cross to workers.
     */
    public function submit(callable $task): Future;

    public function submitToWorker(int $workerId, callable $task): Future;

    public function parallelFor(iterable $items, callable $body): void;

    public function awaitAll(iterable $futures): void;

    /**
     * Submit a Runnable task to a real worker thread (NOT the main thread).
     *
     * Returns a PluginFuture that resolves when the worker finishes.
     * Register callbacks with ->then() to handle the result asynchronously
     * without blocking the main thread.
     *
     * The task class MUST be loaded on the main thread before pool creation
     * (pmmpthread workers cannot autoload). Use class_exists() in bootstrap.
     *
     * @throws \RuntimeException if the runtime has no real worker pool
     */
    public function submitPluginTask(Runnable $task): PluginFuture;

    public function shutdown(): void;
}
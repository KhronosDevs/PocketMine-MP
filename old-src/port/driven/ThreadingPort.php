<?php

declare(strict_types=1);

namespace pocketmine\port\driven;

interface ThreadingPort {
    public function submit(callable $task): Future;

    public function submitToWorker(int $workerId, callable $task): Future;

    public function parallelFor(iterable $items, callable $body): void;

    public function awaitAll(iterable $futures): void;

    public function shutdown(): void;
}
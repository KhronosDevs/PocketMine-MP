<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\threading;

use pocketmine\port\driven\Future;
use pocketmine\Threaded;

final class ThreadedTask extends Threaded {
    public function __construct(
        private callable $task,
        private FutureImpl $future,
    ) {}

    public function execute(): mixed {
        return ($this->task)();
    }

    public function complete(mixed $result): void {
        $this->future->resolve($result);
    }

    public function fail(\Throwable $e): void {
        $this->future->reject($e);
    }
}
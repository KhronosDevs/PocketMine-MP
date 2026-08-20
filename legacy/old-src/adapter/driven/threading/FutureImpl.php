<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\threading;

use pocketmine\port\driven\Future;
use pocketmine\Threaded;

final class FutureImpl extends Threaded implements Future {
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
            $this->result = $result;
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
}
<?php

declare(strict_types=1);

namespace pocketmine\port\driven;

interface Future {
    public function await(): void;

    public function getResult(): mixed;

    public function isDone(): bool;

    public function isCancelled(): bool;

    public function cancel(): bool;
}
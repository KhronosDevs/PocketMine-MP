<?php

declare(strict_types=1);

namespace pocketmine\api\event;

final class ListenerEntry {
    public function __construct(
        public readonly mixed $handler,
        public readonly int $priority,
        public bool $ignoreCancelled = false,
    ) {}
}

<?php

declare(strict_types=1);

namespace pocketmine\api\event;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class EventHandler {
    public function __construct(
        public int $priority = EventPriority::NORMAL,
        public bool $ignoreCancelled = false,
    ) {}
}

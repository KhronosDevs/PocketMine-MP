<?php

declare(strict_types=1);

namespace pocketmine\plugin\api\event;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class EventPriority {
    public const int LOWEST = 0;
    public const int LOW = 1;
    public const int NORMAL = 2;
    public const int HIGH = 3;
    public const int HIGHEST = 4;
    public const int MONITOR = 5;
}

#[\Attribute(\Attribute::TARGET_METHOD)]
final class EventHandler {
    public function __construct(
        public int $priority = EventPriority::NORMAL,
        public bool $ignoreCancelled = false,
    ) {}
}
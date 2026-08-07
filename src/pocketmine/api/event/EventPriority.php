<?php

declare(strict_types=1);

namespace pocketmine\api\event;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class EventPriority {
    public const LOWEST = 0;
    public const LOW = 1;
    public const NORMAL = 2;
    public const HIGH = 3;
    public const HIGHEST = 4;
    public const MONITOR = 5;
}

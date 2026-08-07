<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

use pocketmine\core\ecs\Resource;

#[Resource]
final class TickCounter {
    public function __construct(
        public int $value = 0,
    ) {}
}
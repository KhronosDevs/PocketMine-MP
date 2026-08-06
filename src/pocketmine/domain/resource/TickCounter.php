<?php

declare(strict_types=1);

namespace pocketmine\domain\resource;

use pocketmine\domain\ecs\Resource;

#[Resource]
final class TickCounter {
    public function __construct(
        public int $value = 0,
    ) {}
}
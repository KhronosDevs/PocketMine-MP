<?php

declare(strict_types=1);

namespace pocketmine\domain\resource;

use pocketmine\domain\ecs\Resource;

#[Resource]
final class ServerConfig {
    public function __construct(
        public int $viewDistance = 10,
        public int $tickRate = 20,
        public bool $pvpEnabled = true,
        public bool $spawnAnimals = true,
        public bool $spawnMobs = true,
        public int $difficulty = 1,
    ) {}
}
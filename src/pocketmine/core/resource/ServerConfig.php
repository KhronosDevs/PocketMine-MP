<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

use pocketmine\core\ecs\Resource;

#[Resource]
final class ServerConfig {
    public function __construct(
        public int $viewDistance = 10,
        public int $tickRate = 20,
        public bool $pvpEnabled = true,
        public bool $spawnAnimals = true,
        public bool $spawnMobs = true,
        public int $difficulty = 1,
        public int $seed = 0,
        public int $spawnX = 0,
        public int $spawnY = 64,
        public int $spawnZ = 0,
    ) {}

    public function getSeed(): int {
        return $this->seed !== 0 ? $this->seed : random_int(1, PHP_INT_MAX);
    }
}
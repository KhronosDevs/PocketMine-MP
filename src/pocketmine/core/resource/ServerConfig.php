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
        // A 0 seed means "pick one". It must be picked ONCE and cached, not
        // re-randomized per call: every chunk lookup (safe spawn, chunk
        // streaming, persistence) reads getSeed(), and if each call returned
        // a different value the terrain would differ between lookups.
        if ($this->seed === 0) {
            $this->seed = random_int(1, PHP_INT_MAX);
        }
        return $this->seed;
    }
}
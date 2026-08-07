<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

use pocketmine\core\ecs\Resource;

/**
 * Per-world configuration and mutable world state (time, spawn, rules).
 *
 * Plain data only; the API World facade reads/writes this resource and
 * systems may read it for gameplay rules.
 */
#[Resource]
final class WorldConfig {
    public function __construct(
        public string $name = 'world',
        public string $folderName = 'world',
        public int $seed = 0,
        public int $time = 0,
        public int $spawnX = 0,
        public int $spawnY = 64,
        public int $spawnZ = 0,
        public int $difficulty = 1,
        public int $gameMode = 0,
        public string $generator = 'normal',
        public int $maxPlayers = 20,
        public int $viewDistance = 10,
    ) {}
}

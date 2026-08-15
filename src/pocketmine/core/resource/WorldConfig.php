<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

use pocketmine\core\ecs\Resource;
use pocketmine\core\enum\Difficulty;
use pocketmine\core\enum\GameMode;
use pocketmine\core\enum\GeneratorType;

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
        public Difficulty $difficulty = Difficulty::Easy,
        public GameMode $gameMode = GameMode::Survival,
        public GeneratorType $generator = GeneratorType::Normal,
        public int $maxPlayers = 20,
        public int $viewDistance = 10,
        // Weather (14.22): current state (0 clear / 1 rain / 2 rain+thunder /
        // 3 thunder), ticks of weather left before the next transition, and a
        // per-tick counter the WeatherSystem advances during storms that the
        // network layer reads to time lightning strikes.
        public int $weather = 0,
        public int $weatherDuration = 0,
        public int $lightningTick = 0,
    ) {}
}

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
        // Blocker 1: server.properties white-list=on restricts joins to the
        // white-list.txt entries. Enforced at login alongside bans.
        public bool $whiteList = false,
        // Blocker 1: gamemode new players start in (mirrors the Server facade
        // gamemode from server.properties; applied by PlayerJoinService).
        public int $defaultGameMode = 0,
        // Blocker 1: autosave interval in ticks (server.properties
        // autosave-interval is in seconds; 6000 ticks = 5 minutes).
        public int $autosaveIntervalTicks = 6000,
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
<?php

declare(strict_types=1);

namespace pocketmine\core\system;

use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\constants\BlockIds;
use pocketmine\core\constants\MetadataKeys;
use pocketmine\core\ecs\System;
use pocketmine\core\ecs\World;
use pocketmine\core\enum\EntityType;
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\resource\ServerConfig;
use pocketmine\core\resource\WorldConfig;
use pocketmine\core\service\EntitySpawnService;
use function in_array;

/**
 * Mob spawner (14.3).
 *
 * Sequential (main-thread) system: every SPAWN_INTERVAL ticks it tops up
 * hostile mobs around each alive player, spawning on the terrain surface of a
 * loaded chunk within SPAWN_RADIUS. Respects ServerConfig::spawnMobs, keeps
 * a per-player neighbourhood and a global cap, and never spawns inside the
 * player's column or in ungenerated territory. Spawned mobs carry the full
 * AI/combat component set via EntitySpawnService, so they are immediately
 * visible (entity broadcast) and alive (AISystem targets players).
 */
final class MobSpawnerSystem implements System {
    /** Attempt population every N ticks (40 = 2 seconds at 20 TPS). */
    public const SPAWN_INTERVAL = 40;
    /** Max hostile mobs within SPAWN_RADIUS of a single player. */
    public const MAX_MOBS_PER_PLAYER = 8;
    /** Hard global cap on hostile mobs in the world. */
    public const MAX_TOTAL_MOBS = 40;
    /** Spawn in an annulus [MIN, MAX] blocks around the player. */
    public const MIN_SPAWN_DISTANCE = 8;
    public const SPAWN_RADIUS = 24;
    /** Hostile mobs farther than this from EVERY player are despawned. */
    public const DESPAWN_DISTANCE = 128.0;

    /**
     * Overworld hostile spawn weights. Nether mobs are excluded from this
     * pool so they never spawn in the overworld.
     */
    private const OVERWORLD_WEIGHTS = [
        EntityType::Zombie->value => 32,
        EntityType::Skeleton->value => 26,
        EntityType::Spider->value => 22,
        EntityType::Creeper->value => 20,
        EntityType::Enderman->value => 6,
        EntityType::Slime->value => 5,
        EntityType::CaveSpider->value => 4,
        EntityType::Husk->value => 3,
        EntityType::Stray->value => 3,
        EntityType::Witch->value => 2,
        EntityType::ZombieVillager->value => 2,
        EntityType::Silverfish->value => 1,
    ];

    /**
     * Nether hostile spawn weights. Overworld mobs are excluded so they
     * never spawn in the nether.
     */
    private const NETHER_WEIGHTS = [
        EntityType::PigZombie->value => 20,
        EntityType::Ghast->value => 4,
        EntityType::Blaze->value => 4,
        EntityType::LavaSlime->value => 3,
        EntityType::Skeleton->value => 2,  // wither skeleton variant
        EntityType::Slime->value => 2,
        EntityType::Enderman->value => 1,
    ];

    private int $tickCounter = 0;

    public function run(World $world, float $deltaTime): void {
        $this->tickCounter++;
        if ($this->tickCounter % self::SPAWN_INTERVAL !== 0) {
            return;
        }

        $kernel = \pocketmine\Kernel::getInstance();
        if ($kernel === null) {
            return;
        }
        // Per-world mob spawning toggle: WorldConfig::spawnMobs replaces
        // the old global ServerConfig::spawnMobs.
        if ($worldConfig instanceof WorldConfig && !$worldConfig->spawnMobs) {
            return; // mob spawning disabled for this world
        }
        // 14.6: hostile mobs only spawn after dusk (time >= 12000). During
        // the day the world is quiet; the night gate makes day/night mean
        // something in the game rather than mobs appearing 24/7.
        $worldConfig = $world->getResourceRegistry()->get(WorldConfig::class);
        if ($worldConfig instanceof WorldConfig && !TimeSystem::isNight($worldConfig->time)) {
            return; // daylight: no hostile spawns
        }
        $spawnService = $kernel->getEntitySpawnService();
        if (!$spawnService instanceof EntitySpawnService) {
            return;
        }

        $store = $world->getResourceRegistry()->get(ChunkStore::class);
        $store = $store instanceof ChunkStore ? $store : null;

        // Single entity pass: classify all entities into players (alive,
        // world 0) and hostiles (world 0) in one scan. Previous code ran
        // 3-4 separate full-entity scans (build players, despawn sweep,
        // countHostileMobs, countHostileNear × players). This replaces
        // all of them with one pass.
        $players = [];
        $hostiles = []; // list of PositionComponent for near-count
        $allHostiles = []; // for despawn check
        foreach ($world->getEntities() as $entity) {
            $worldComponent = $entity->get(\pocketmine\core\component\WorldComponent::class);
            if ($worldComponent !== null && $worldComponent->id !== 0) {
                continue;
            }
            if ($entity->has(PlayerTag::class)) {
                $health = $entity->get(HealthComponent::class);
                $pos = $entity->get(PositionComponent::class);
                if ($health !== null && $health->current > 0 && $pos !== null) {
                    $players[] = $pos;
                }
            } else {
                $meta = $entity->get(MetadataComponent::class);
                if ($meta !== null && $meta->get(MetadataKeys::HOSTILE)) {
                    $pos = $entity->get(PositionComponent::class);
                    if ($pos !== null) {
                        $hostiles[] = $pos;
                        $allHostiles[] = $entity;
                    }
                }
            }
        }
        if (empty($players)) {
            return;
        }

        // Periodic cleanup: despawn hostile mobs stuck against walls / unreachable
        // spots (their AI state never changes, so they permanently occupy spawn
        // budget). Runs every 10 spawn cycles (~40 seconds) to avoid per-tick cost.
        if ($this->tickCounter % (self::SPAWN_INTERVAL * 10) === 0) {
            $despawnSvc = $kernel->getEntityDespawnService();
            if ($despawnSvc !== null) {
                $despawnSvc->despawnInactiveEntities();
            }
        }

        // Despawn hostiles far from all players (inlined — no separate scan).
        $maxDespawnSq = self::DESPAWN_DISTANCE * self::DESPAWN_DISTANCE;
        $despawned = 0;
        foreach ($allHostiles as $entity) {
            $pos = $entity->get(PositionComponent::class);
            if ($pos === null) {
                continue;
            }
            $near = false;
            foreach ($players as $playerPos) {
                $dx = $pos->x - $playerPos->x;
                $dy = $pos->y - $playerPos->y;
                $dz = $pos->z - $playerPos->z;
                if ($dx * $dx + $dy * $dy + $dz * $dz <= $maxDespawnSq) {
                    $near = true;
                    break;
                }
            }
            if (!$near) {
                $ref = \pocketmine\core\ecs\EntityRef::create($entity->id, $world);
                $kernel->getEntityDespawnService()->despawn($ref, false);
                $despawned++;
            }
        }

        $totalHostile = max(0, count($hostiles) - $despawned);
        if ($totalHostile >= self::MAX_TOTAL_MOBS) {
            return;
        }

        $spawnRadiusSq = self::SPAWN_RADIUS * self::SPAWN_RADIUS;
        foreach ($players as $playerPos) {
            if ($totalHostile >= self::MAX_TOTAL_MOBS) {
                break;
            }
            // Count nearby hostiles (inlined — no separate scan).
            $nearCount = 0;
            foreach ($hostiles as $hPos) {
                $dx = $hPos->x - $playerPos->x;
                $dz = $hPos->z - $playerPos->z;
                if ($dx * $dx + $dz * $dz <= $spawnRadiusSq) {
                    $nearCount++;
                }
            }
            if ($nearCount >= self::MAX_MOBS_PER_PLAYER) {
                continue;
            }

            $x = $playerPos->x;
            $z = $playerPos->z;
            $y = $playerPos->y;
            $found = false;
            for ($attempt = 0; $attempt < 6 && !$found; $attempt++) {
                $angle = mt_rand(0, 360);
                $dist = mt_rand(self::MIN_SPAWN_DISTANCE, self::SPAWN_RADIUS);
                $x = $playerPos->x + cos(deg2rad($angle)) * $dist;
                $z = $playerPos->z + sin(deg2rad($angle)) * $dist;
                $chunkX = (int)floor($x / 16);
                $chunkZ = (int)floor($z / 16);
                // Only spawn on terrain that is actually loaded: a player's
                // view chunks are, so a real spawn is nearly always the first
                // attempt; ungenerated territory is skipped entirely.
                if ($store !== null && $store->isLoaded($chunkX, $chunkZ)) {
                    $top = $store->getHighestBlockAt((int)floor($x), (int)floor($z));
                    // Skip water/lava columns: mobs must not spawn in liquid.
                    // The next attempt picks a different spot.
                    if ($top > 0 && !in_array($store->getBlock((int)floor($x), $top, (int)floor($z)), BlockIds::LIQUIDS, true)) {
                        $y = $top + 1;
                        // 14.29: hostile mobs spawn only in darkness - a
                        // torch-lit area (block light > 0) is protected, so
                        // placing torches actually keeps mobs away. Sky light
                        // is ignored because the spawner already gates on the
                        // night window (sky light is effectively 0 at night).
                        if ($store->getBlockLightLevel((int)floor($x), $y, (int)floor($z)) > 0) {
                            continue;
                        }
                        $found = true;
                    }
                }
            }
            if (!$found) {
                continue; // never found loaded terrain: skip this player
            }

            $isNether = $worldConfig instanceof WorldConfig
                && $worldConfig->generator === \pocketmine\core\enum\GeneratorType::Nether;
            $spawnService->spawnMob(self::pickHostileType($isNether), $x, $y, $z);
            $totalHostile++;
        }
    }

    /** Weighted random pick from the appropriate world pool. */
    private function pickHostileType(bool $isNether): EntityType {
        $pool = $isNether ? self::NETHER_WEIGHTS : self::OVERWORLD_WEIGHTS;
        $total = array_sum($pool);
        $roll = mt_rand(1, $total);
        $cumulative = 0;
        foreach ($pool as $type => $weight) {
            $cumulative += $weight;
            if ($roll <= $cumulative) {
                return EntityType::from($type);
            }
        }
        return EntityType::Zombie; // unreachable, but keeps static analysis happy
    }

}

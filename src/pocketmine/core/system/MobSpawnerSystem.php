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
     * Hostile spawn weights - common overworld mobs dominate, rare/misc
     * (Ghast, Blaze, Silverfish, ...) only appear occasionally. Keys are
     * EntityType values (enums can't be array keys), values are relative
     * weights for the night spawner.
     */
    private const HOSTILE_WEIGHTS = [
        EntityType::Zombie->value => 32,
        EntityType::Skeleton->value => 26,
        EntityType::Spider->value => 22,
        EntityType::Creeper->value => 20,
        EntityType::Enderman->value => 6,
        EntityType::Slime->value => 5,
        EntityType::CaveSpider->value => 4,
        EntityType::Husk->value => 3,
        EntityType::Stray->value => 3,
        EntityType::PigZombie->value => 2,
        EntityType::Witch->value => 2,
        EntityType::ZombieVillager->value => 2,
        EntityType::LavaSlime->value => 1,
        EntityType::Silverfish->value => 1,
        EntityType::Ghast->value => 1,
        EntityType::Blaze->value => 1,
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
        $config = $kernel->getResourceRegistry()->get(ServerConfig::class);
        if ($config instanceof ServerConfig && !$config->spawnMobs) {
            return; // mob spawning disabled by configuration
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

        // Alive players (dead players do not attract spawns). The spawner
        // only populates the default world (spawnMob targets world 0), so
        // players in another game world (nether, /world lobbies) must not
        // attract spawns at their coordinates in the overworld.
        $players = [];
        foreach ($world->getEntities() as $entity) {
            if (!$entity->has(PlayerTag::class)) {
                continue;
            }
            $worldComponent = $entity->get(\pocketmine\core\component\WorldComponent::class);
            if ($worldComponent !== null && $worldComponent->id !== 0) {
                continue;
            }
            $health = $entity->get(HealthComponent::class);
            $pos = $entity->get(PositionComponent::class);
            if ($health === null || $health->current <= 0 || $pos === null) {
                continue;
            }
            $players[] = $pos;
        }
        if (empty($players)) {
            return;
        }

        // Free capacity before checking the caps: hostile mobs farther than
        // DESPAWN_DISTANCE from EVERY player are abandoned (nobody can reach
        // them, but they still count against MAX_TOTAL_MOBS). Without this
        // sweep the cap saturates after long sessions and hostile spawning
        // dies permanently. The despawn is queued and flushed on the next
        // world tick, so subtract it from the count to unblock this cycle.
        $despawned = $kernel->getEntityDespawnService()->despawnFarFromAllPlayers($players, self::DESPAWN_DISTANCE);

        $totalHostile = max(0, $this->countHostileMobs($world) - $despawned);
        if ($totalHostile >= self::MAX_TOTAL_MOBS) {
            return;
        }

        foreach ($players as $playerPos) {
            if ($totalHostile >= self::MAX_TOTAL_MOBS) {
                break;
            }
            if ($this->countHostileNear($world, $playerPos) >= self::MAX_MOBS_PER_PLAYER) {
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

            $spawnService->spawnMob(self::pickHostileType(), $x, $y, $z);
            $totalHostile++;
        }
    }

    /** Weighted random pick from HOSTILE_WEIGHTS. */
    private function pickHostileType(): EntityType {
        $total = array_sum(self::HOSTILE_WEIGHTS);
        $roll = mt_rand(1, $total);
        $cumulative = 0;
        foreach (self::HOSTILE_WEIGHTS as $type => $weight) {
            $cumulative += $weight;
            if ($roll <= $cumulative) {
                return EntityType::from($type);
            }
        }
        return EntityType::Zombie; // unreachable, but keeps static analysis happy
    }

    private function countHostileMobs(World $world): int {
        // Default-world only (world 0): the spawner populates that world, so
        // hostiles in other dimensions must not consume its budget.
        $count = 0;
        foreach ($world->getEntities() as $entity) {
            $worldComponent = $entity->get(\pocketmine\core\component\WorldComponent::class);
            if ($worldComponent !== null && $worldComponent->id !== 0) {
                continue;
            }
            $meta = $entity->get(MetadataComponent::class);
            if ($meta !== null && $meta->get(MetadataKeys::HOSTILE)) {
                $count++;
            }
        }
        return $count;
    }

    private function countHostileNear(World $world, PositionComponent $center): int {
        // Same world scoping as countHostileMobs; X/Z-only distance is fine
        // within one dimension.
        $count = 0;
        $rangeSq = self::SPAWN_RADIUS * self::SPAWN_RADIUS;
        foreach ($world->getEntities() as $entity) {
            $meta = $entity->get(MetadataComponent::class);
            if ($meta === null || !$meta->get(MetadataKeys::HOSTILE)) {
                continue;
            }
            $pos = $entity->get(PositionComponent::class);
            if ($pos === null) {
                continue;
            }
            $dx = $pos->x - $center->x;
            $dz = $pos->z - $center->z;
            if ($dx * $dx + $dz * $dz <= $rangeSq) {
                $count++;
            }
        }
        return $count;
    }
}

<?php

declare(strict_types=1);

namespace pocketmine\core\service;

use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\RotationComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\component\tags\DeadTag;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;
use pocketmine\core\resource\BlockRegistry;
use pocketmine\core\resource\ChunkStore;
use pocketmine\port\driven\StoragePort;
use pocketmine\port\driving\EventPort;

final class PlayerRespawnService {
    public function __construct(
        private readonly World $world,
        private readonly StoragePort $storagePort,
        private readonly EventPort $eventPort,
    ) {}

    public function respawn(EntityRef $entityRef): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;

        // Blocker 4: PlayerRespawnEvent fires before the respawn mutation so
        // plugins can intercept (e.g. set a custom respawn point).
        $this->eventPort->emit(new \pocketmine\api\event\PlayerRespawnEvent(
            $this->wrapApiPlayer($entityRef),
        ));
        
        // Remove dead tag
        $entity->remove(DeadTag::class);
        
        // Reset health
        $health = $entity->get(HealthComponent::class);
        if ($health) {
            $health->current = $health->max;
        }
        
        // Reset velocity
        $velocity = $entity->get(VelocityComponent::class);
        if ($velocity) {
            $velocity->x = 0;
            $velocity->y = 0;
            $velocity->z = 0;
        }
        
        // Clear inventory (or keep based on game rules)
        $this->handleInventoryOnRespawn($entity);
        
        // Teleport to spawn
        $this->teleportToSpawn($entityRef);
        
        // Clear effects
        $this->clearEffects($entity);
        
        // Reset metadata
        $this->resetMetadata($entity);
    }

    private function wrapApiPlayer(EntityRef $ref): \pocketmine\api\entity\Player {
        $entity = \pocketmine\api\entity\Entity::wrap($ref, $this->world);
        return $entity instanceof \pocketmine\api\entity\Player
            ? $entity
            : new \pocketmine\api\entity\Player($ref, $this->world);
    }

    private function handleInventoryOnRespawn(\pocketmine\core\ecs\Entity $entity): void {
        $metadata = $entity->get(MetadataComponent::class);
        $keepInventory = $metadata?->get(\pocketmine\core\constants\MetadataKeys::KEEP_INVENTORY) ?? false;
        
        if (!$keepInventory) {
            $inventory = $entity->get(InventoryComponent::class);
            if ($inventory) {
                $inventory->clear();
            }
        }
    }

    private function teleportToSpawn(EntityRef $entityRef): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;

        // Personal spawn first: a player who slept in a bed respawns there
        // (persisted in their metadata by sleepInBed). Falls back to the
        // world spawn from the config.
        $meta = $entity->get(MetadataComponent::class);
        $sx = $meta?->get('spawnX');
        $sy = $meta?->get('spawnY');
        $sz = $meta?->get('spawnZ');
        if ($sx !== null && $sy !== null && $sz !== null) {
            $safe = $this->findSafeAbove((float)$sx, (float)$sy, (float)$sz);
            if ($safe !== null) {
                $entityRef->teleport($safe[0], $safe[1], $safe[2], 0, 0);
                return;
            }
            // Personal spawn is inside terrain — fall through to world spawn.
        }

        // World spawn from config — scan upward for safe position.
        $kernel = \pocketmine\Kernel::getInstance();
        $config = $kernel?->getResourceRegistry()->get(\pocketmine\core\resource\ServerConfig::class);
        if ($config !== null) {
            $safe = $this->findSafeAbove($config->spawnX, $config->spawnY, $config->spawnZ);
            if ($safe !== null) {
                $entityRef->teleport($safe[0], $safe[1], $safe[2], 0, 0);
                return;
            }
            // Last resort: raw config (may be inside terrain)
            $entityRef->teleport($config->spawnX, $config->spawnY, $config->spawnZ, 0, 0);
        }
    }

    /**
     * Given a spawn coordinate, scan upward to find 2 consecutive air blocks
     * (feet + head) so the player never suffocates. Returns [x, y, z] or
     * null if no safe spot exists within 20 blocks above.
     */
    private function findSafeAbove(float $x, float $y, float $z): ?array {
        $kernel = \pocketmine\Kernel::getInstance();
        $registry = $kernel?->getResourceRegistry();
        $store = $registry?->get(ChunkStore::class);
        $blocks = $registry?->get(BlockRegistry::class);
        if (!$store instanceof ChunkStore || !$blocks instanceof BlockRegistry) {
            return null;
        }
        $bx = (int)floor($x);
        $bz = (int)floor($z);
        // Start at the configured Y, scan upward for 2 air blocks
        $startY = max(1, (int)floor($y));
        for ($by = $startY; $by < min($startY + 20, 255); $by++) {
            if (!$blocks->isSolid($store->getBlock($bx, $by, $bz))
                && !$blocks->isSolid($store->getBlock($bx, $by + 1, $bz))) {
                return [(float)$bx + 0.5, (float)$by, (float)$bz + 0.5];
            }
        }
        return null;
    }

    /**
     * Is a position safe? Feet and head blocks must be non-solid.
     */
    private function isSafePosition(float $x, float $y, float $z): bool {
        $kernel = \pocketmine\Kernel::getInstance();
        $registry = $kernel?->getResourceRegistry();
        $store = $registry?->get(ChunkStore::class);
        $blocks = $registry?->get(BlockRegistry::class);
        if (!$store instanceof ChunkStore || !$blocks instanceof BlockRegistry) {
            return false;
        }
        $bx = (int)floor($x);
        $by = (int)floor($y);
        $bz = (int)floor($z);
        return !$blocks->isSolid($store->getBlock($bx, $by, $bz))
            && !$blocks->isSolid($store->getBlock($bx, $by + 1, $bz));
    }

    private function clearEffects(\pocketmine\core\ecs\Entity $entity): void {
        $effects = $entity->get(\pocketmine\core\component\EffectComponent::class);
        if ($effects) {
            $effects->clear();
        }
    }

    private function resetMetadata(\pocketmine\core\ecs\Entity $entity): void {
        $metadata = $entity->get(MetadataComponent::class);
        if ($metadata) {
            // Snapshot persistent player state that must survive respawn.
            $preserve = [];
            foreach ([
                \pocketmine\core\constants\MetadataKeys::UNIQUE_ID,
                \pocketmine\core\constants\MetadataKeys::USERNAME,
                \pocketmine\core\constants\MetadataKeys::GAMEMODE,
                'permissions',
            ] as $key) {
                $val = $metadata->get($key);
                if ($val !== null) {
                    $preserve[$key] = $val;
                }
            }

            $metadata->data = [];

            foreach ($preserve as $key => $val) {
                $metadata->set($key, $val);
            }
        }
    }
}
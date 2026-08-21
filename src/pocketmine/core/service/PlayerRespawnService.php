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
        // world spawn from the config - PlayerJoinService resolves that to a
        // terrain-safe Y so respawn never lands inside a hill.
        $meta = $entity->get(MetadataComponent::class);
        $sx = $meta?->get('spawnX');
        $sy = $meta?->get('spawnY');
        $sz = $meta?->get('spawnZ');
        if ($sx !== null && $sy !== null && $sz !== null) {
            $entityRef->teleport((float)$sx, (float)$sy, (float)$sz, 0, 0);
            return;
        }

        $kernel = \pocketmine\Kernel::getInstance();
        $config = $kernel?->getResourceRegistry()->get(\pocketmine\core\resource\ServerConfig::class);
        if ($config !== null) {
            $entityRef->teleport($config->spawnX, $config->spawnY, $config->spawnZ, 0, 0);
        }
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
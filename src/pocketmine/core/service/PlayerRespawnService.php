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

final class PlayerRespawnService {
    public function __construct(
        private readonly World $world,
        private readonly StoragePort $storagePort,
    ) {}

    public function respawn(EntityRef $entityRef): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;
        
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

    private function handleInventoryOnRespawn(\pocketmine\core\ecs\Entity $entity): void {
        $metadata = $entity->get(MetadataComponent::class);
        $keepInventory = $metadata?->get('keepInventory') ?? false;
        
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
        
        $kernel = \pocketmine\Kernel::getInstance();
        $config = $kernel?->getResourceRegistry()->get(\pocketmine\core\resource\ServerConfig::class);
        if ($config !== null) {
            $entityRef->teleport($config->spawnX, $config->spawnY + 1, $config->spawnZ, 0, 0);
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
            // Keep persistent metadata (uniqueId, username, etc.)
            $uniqueId = $metadata->get('uniqueId');
            $username = $metadata->get('username');
            
            $metadata->data = [];
            
            if ($uniqueId) $metadata->set('uniqueId', $uniqueId);
            if ($username) $metadata->set('username', $username);
        }
    }
}
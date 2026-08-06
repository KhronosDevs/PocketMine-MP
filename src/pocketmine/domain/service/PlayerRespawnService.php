<?php

declare(strict_types=1);

namespace pocketmine\domain\service;

use pocketmine\domain\component\HealthComponent;
use pocketmine\domain\component\InventoryComponent;
use pocketmine\domain\component\MetadataComponent;
use pocketmine\domain\component\PositionComponent;
use pocketmine\domain\component\RotationComponent;
use pocketmine\domain\component\VelocityComponent;
use pocketmine\domain\component\tags\DeadTag;
use pocketmine\domain\ecs\EntityRef;
use pocketmine\domain\ecs\World;
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

    private function handleInventoryOnRespawn(\pocketmine\entity\Entity $entity): void {
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
        
        $server = \pocketmine\Server::getInstance();
        $level = $server?->getDefaultLevel();
        
        if ($level) {
            $spawn = $level->getSpawn();
            $entityRef->teleport($spawn->x, $spawn->y + 1, $spawn->z, 0, 0);
        }
    }

    private function clearEffects(\pocketmine\entity\Entity $entity): void {
        $effects = $entity->get(\pocketmine\domain\component\EffectComponent::class);
        if ($effects) {
            $effects->clear();
        }
    }

    private function resetMetadata(\pocketmine\entity\Entity $entity): void {
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
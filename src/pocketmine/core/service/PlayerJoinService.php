<?php

declare(strict_types=1);

namespace pocketmine\core\service;

use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\RotationComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\ecs\EntityBuilder;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;
use pocketmine\port\driven\NetworkPort;
use pocketmine\port\driven\PlayerRef;
use pocketmine\port\driven\StoragePort;
use pocketmine\port\driven\WorldGenPort;

final class PlayerJoinService {
    public function __construct(
        private readonly World $world,
        private readonly NetworkPort $networkPort,
        private readonly StoragePort $storagePort,
        private readonly WorldGenPort $worldGenPort,
    ) {}

    public function handleJoin(PlayerRef $playerRef, string $username): EntityRef {
        // Create or load player entity
        $entityRef = $this->createOrLoadPlayer($playerRef, $username);
        
        // Send join packets (spawn position, inventory, etc.)
        $this->sendJoinPackets($entityRef);
        
        // Load player data from storage
        $this->loadPlayerData($entityRef);
        
        // Notify other players
        $this->broadcastPlayerJoin($entityRef);
        
        return $entityRef;
    }

    private function createOrLoadPlayer(PlayerRef $playerRef, string $username): EntityRef {
        // Try to load existing player data
        $savedData = $this->storagePort->loadEntity($playerRef->uniqueId);
        
        if ($savedData !== null && $savedData->type === 'Player') {
            // Recreate from saved data
            $entityRef = $this->world->spawn(
                (new EntityBuilder())
                    ->with(new PositionComponent($savedData->x, $savedData->y, $savedData->z))
                    ->with(new RotationComponent($savedData->yaw, $savedData->pitch))
                    ->with(new VelocityComponent())
                    ->with(new HealthComponent())
                    ->with(new InventoryComponent(36))
                    ->with(new MetadataComponent())
                    ->withTag('player')
                    
            );
            
            // Restore components from saved data
            $this->restoreComponents($entityRef, $savedData);
            
            return $entityRef;
        }
        
        // New player - spawn at world spawn
        $spawn = $this->getWorldSpawn();
        
        $entityRef = $this->world->spawn(
            (new EntityBuilder())
                ->with(new PositionComponent($spawn->x, $spawn->y, $spawn->z))
                ->with(new RotationComponent(0, 0))
                ->with(new VelocityComponent())
                ->with(new HealthComponent(20, 20))
                ->with(new InventoryComponent(36))
                ->with(new MetadataComponent())
                ->withTag('player')
                
        );
        
        // Store mapping
        $entity = $entityRef->getEntity();
        if ($entity) {
            $entity->get(MetadataComponent::class)?->set('username', $username);
            $entity->get(MetadataComponent::class)?->set('uniqueId', $playerRef->uniqueId);
        }
        
        return $entityRef;
    }

    private function getWorldSpawn(): PositionComponent {
        // World spawn is derived from the ECS resource config when present;
        // otherwise fall back to the default spawn point (0, 64, 0).
        $kernel = \pocketmine\Kernel::getInstance();
        if ($kernel !== null) {
            $config = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ServerConfig::class);
            if ($config !== null && $config->spawnX !== 0 || $config->spawnY !== 0 || $config->spawnZ !== 0) {
                return new PositionComponent($config->spawnX, $config->spawnY, $config->spawnZ);
            }
        }
        
        return new PositionComponent(0, 64, 0);
    }

    private function sendJoinPackets(EntityRef $entityRef): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;
        
        $position = $entity->get(PositionComponent::class);
        $rotation = $entity->get(RotationComponent::class);
        $health = $entity->get(HealthComponent::class);
        $inventory = $entity->get(InventoryComponent::class);
        
        if ($position && $rotation) {
            // Send StartGamePacket (protocol 84) - would be created in NetworkSyncSystem
            // For now, we just ensure the entity is ready for network sync
        }
    }

    private function loadPlayerData(EntityRef $entityRef): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;
        
        $metadata = $entity->get(MetadataComponent::class);
        $uniqueId = $metadata?->get('uniqueId');
        
        if ($uniqueId) {
            $savedData = $this->storagePort->loadEntity($uniqueId);
            if ($savedData) {
                $this->restoreComponents($entityRef, $savedData);
            }
        }
    }

    private function restoreComponents(EntityRef $entityRef, \pocketmine\port\driven\EntitySnapshot $savedData): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;
        
        // Restore health
        $health = $entity->get(\pocketmine\core\component\HealthComponent::class);
        if ($health && isset($savedData->components['pocketmine\core\component\HealthComponent'])) {
            $healthData = $savedData->components['pocketmine\core\component\HealthComponent'];
            $health->current = $healthData['current'] ?? 20;
            $health->max = $healthData['max'] ?? 20;
        }
        
        // Restore inventory
        $inventory = $entity->get(\pocketmine\core\component\InventoryComponent::class);
        if ($inventory && isset($savedData->components['pocketmine\core\component\InventoryComponent'])) {
            $invData = $savedData->components['pocketmine\core\component\InventoryComponent'];
            if (isset($invData['slots'])) {
                foreach ($invData['slots'] as $slot => $itemData) {
                    $inventory->set($slot, \pocketmine\core\component\ItemStack::fromArray($itemData));
                }
            }
        }
        
        // Restore metadata
        $metadata = $entity->get(\pocketmine\core\component\MetadataComponent::class);
        if ($metadata && isset($savedData->components['pocketmine\core\component\MetadataComponent'])) {
            $metaData = $savedData->components['pocketmine\core\component\MetadataComponent'];
            if (isset($metaData['data'])) {
                foreach ($metaData['data'] as $key => $value) {
                    $metadata->set($key, $value);
                }
            }
        }
    }

    private function broadcastPlayerJoin(EntityRef $entityRef): void {
        // NetworkSyncSystem will handle sending AddPlayerPacket to nearby players
        // This service just ensures the entity is properly set up
    }
}
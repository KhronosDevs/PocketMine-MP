<?php

declare(strict_types=1);

namespace pocketmine\domain\service;

use pocketmine\domain\component\HealthComponent;
use pocketmine\domain\component\InventoryComponent;
use pocketmine\domain\component\MetadataComponent;
use pocketmine\domain\component\PositionComponent;
use pocketmine\domain\component\RotationComponent;
use pocketmine\domain\component\VelocityComponent;
use pocketmine\domain\component\tags\PlayerTag;
use pocketmine\domain\ecs\EntityBuilder;
use pocketmine\domain\ecs\EntityRef;
use pocketmine\domain\ecs\World;
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
                    ->build($this->world)
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
                ->build($this->world)
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
        $server = \pocketmine\Server::getInstance();
        $level = $server?->getDefaultLevel();
        
        if ($level) {
            $spawn = $level->getSpawn();
            return new PositionComponent($spawn->x, $spawn->y, $spawn->z);
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
        $health = $entity->get(\pocketmine\domain\component\HealthComponent::class);
        if ($health && isset($savedData->components['pocketmine\domain\component\HealthComponent'])) {
            $healthData = $savedData->components['pocketmine\domain\component\HealthComponent'];
            $health->current = $healthData['current'] ?? 20;
            $health->max = $healthData['max'] ?? 20;
        }
        
        // Restore inventory
        $inventory = $entity->get(\pocketmine\domain\component\InventoryComponent::class);
        if ($inventory && isset($savedData->components['pocketmine\domain\component\InventoryComponent'])) {
            $invData = $savedData->components['pocketmine\domain\component\InventoryComponent'];
            if (isset($invData['slots'])) {
                foreach ($invData['slots'] as $slot => $itemData) {
                    $inventory->set($slot, \pocketmine\domain\component\ItemStack::fromArray($itemData));
                }
            }
        }
        
        // Restore metadata
        $metadata = $entity->get(\pocketmine\domain\component\MetadataComponent::class);
        if ($metadata && isset($savedData->components['pocketmine\domain\component\MetadataComponent'])) {
            $metaData = $savedData->components['pocketmine\domain\component\MetadataComponent'];
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
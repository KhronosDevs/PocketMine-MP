<?php

declare(strict_types=1);

namespace pocketmine\domain\service;

use pocketmine\domain\ecs\EntityRef;
use pocketmine\domain\ecs\World;
use pocketmine\port\driven\NetworkPort;
use pocketmine\port\driven\StoragePort;
use pocketmine\port\driven\PlayerRef;

final class PlayerLeaveService {
    public function __construct(
        private readonly World $world,
        private readonly NetworkPort $networkPort,
        private readonly StoragePort $storagePort,
    ) {}

    public function handleLeave(EntityRef $entityRef, string $reason = ""): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;
        
        // Save player data before removing
        $this->savePlayerData($entityRef);
        
        // Broadcast player removal to nearby players
        $this->broadcastPlayerLeave($entityRef);
        
        // Despawn entity
        $this->world->despawn($entity);
    }

    public function handleDisconnect(PlayerRef $playerRef, string $reason = ""): void {
        // Find entity by uniqueId
        $entityRef = $this->findEntityByUniqueId($playerRef->uniqueId);
        if ($entityRef) {
            $this->handleLeave($entityRef, $reason);
        }
    }

    private function findEntityByUniqueId(string $uniqueId): ?EntityRef {
        $query = $this->world->query()
            ->with(\pocketmine\domain\component\MetadataComponent::class)
            ->withTag('player')
            ->build();
        
        foreach ($query as $entity) {
            $metadata = $entity->get(\pocketmine\domain\component\MetadataComponent::class);
            if ($metadata && $metadata->get('uniqueId') === $uniqueId) {
                return \pocketmine\domain\ecs\EntityRef::create($entity->id, $this->world);
            }
        }
        
        return null;
    }

    private function savePlayerData(EntityRef $entityRef): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;
        
        $metadata = $entity->get(\pocketmine\domain\component\MetadataComponent::class);
        $uniqueId = $metadata?->get('uniqueId');
        
        if (!$uniqueId) return;
        
        // Serialize entity components
        $components = [];
        foreach ($entity->getComponents() as $type => $component) {
            $components[$type] = \pocketmine\domain\ecs\ComponentSerializer::serialize($component);
        }
        
        $position = $entity->get(\pocketmine\domain\component\PositionComponent::class);
        $rotation = $entity->get(\pocketmine\domain\component\RotationComponent::class);
        
        $snapshot = new \pocketmine\port\driven\EntitySnapshot(
            $uniqueId,
            'Player',
            $position?->x ?? 0,
            $position?->y ?? 64,
            $position?->z ?? 0,
            $rotation?->yaw ?? 0,
            $rotation?->pitch ?? 0,
            $components
        );
        
        $this->storagePort->saveEntity($snapshot);
    }

    private function broadcastPlayerLeave(EntityRef $entityRef): void {
        // NetworkSyncSystem will handle sending RemoveEntityPacket
        // This service just triggers the cleanup
    }
}
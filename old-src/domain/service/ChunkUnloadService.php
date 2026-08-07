<?php

declare(strict_types=1);

namespace pocketmine\domain\service;

use pocketmine\domain\component\PositionComponent;
use pocketmine\domain\component\VelocityComponent;
use pocketmine\domain\ecs\World;
use pocketmine\port\driven\StoragePort;

final class ChunkUnloadService {
    public function __construct(
        private readonly World $world,
        private readonly StoragePort $storagePort,
    ) {}

    public function unloadChunk(int $chunkX, int $chunkZ): void {
        // Get entities in the chunk
        $entities = $this->getEntitiesInChunk($chunkX, $chunkZ);
        
        // Save entities that are in this chunk
        foreach ($entities as $entityRef) {
            $this->saveEntity($entityRef);
        }
        
        // Save chunk data (would be called with actual chunk data)
        // $this->saveChunkData($chunkX, $chunkZ, $chunkData);
    }

    public function unloadUnusedChunks(int $maxLoadedChunks = 10000): void {
        // In a real implementation, this would track loaded chunks
        // and unload those far from players
    }

    private function getEntitiesInChunk(int $chunkX, int $chunkZ): array {
        $entities = [];
        
        $query = $this->world->query()
            ->with(PositionComponent::class)
            ->build();
        
        $minX = $chunkX * 16;
        $maxX = $minX + 15;
        $minZ = $chunkZ * 16;
        $maxZ = $minZ + 15;
        
        foreach ($query as $entity) {
            $position = $entity->get(PositionComponent::class);
            if (!$position) continue;
            
            $entityChunkX = (int)floor($position->x / 16);
            $entityChunkZ = (int)floor($position->z / 16);
            
            if ($entityChunkX === $chunkX && $entityChunkZ === $chunkZ) {
                $entities[] = \pocketmine\domain\ecs\EntityRef::create($entity->id, $this->world);
            }
        }
        
        return $entities;
    }

    private function saveEntity(\pocketmine\domain\ecs\EntityRef $entityRef): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;
        
        $metadata = $entity->get(\pocketmine\domain\component\MetadataComponent::class);
        $uniqueId = $metadata?->get('uniqueId');
        
        if (!$uniqueId) return;
        
        $components = [];
        foreach ($entity->getComponents() as $type => $component) {
            $components[$type] = \pocketmine\domain\ecs\ComponentSerializer::serialize($component);
        }
        
        $position = $entity->get(\pocketmine\domain\component\PositionComponent::class);
        $rotation = $entity->get(\pocketmine\domain\component\RotationComponent::class);
        
        $snapshot = new \pocketmine\port\driven\EntitySnapshot(
            $uniqueId,
            get_class($entity),
            $position?->x ?? 0,
            $position?->y ?? 0,
            $position?->z ?? 0,
            $rotation?->yaw ?? 0,
            $rotation?->pitch ?? 0,
            $components
        );
        
        $this->storagePort->saveEntity($snapshot);
    }

    public function migrateEntity(\pocketmine\domain\ecs\EntityRef $entityRef, int $newChunkX, int $newChunkZ): void {
        // Entity moved to a new chunk - update chunk tracking
        // In a full implementation, this would update chunk-entity mappings
    }
}
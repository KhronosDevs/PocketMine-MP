<?php

declare(strict_types=1);

namespace pocketmine\core\service;

use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\ecs\World;
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\resource\WorldRegistry;
use pocketmine\port\driven\StoragePort;

final class ChunkUnloadService {
    public function __construct(
        private readonly World $world,
        private readonly StoragePort $storagePort,
    ) {}

    public function unloadChunk(int $chunkX, int $chunkZ, int $worldId = 0): void {
        // Get entities in the chunk
        $entities = $this->getEntitiesInChunk($chunkX, $chunkZ, $worldId);
        
        // Save entities that are in this chunk
        foreach ($entities as $entityRef) {
            $this->saveEntity($entityRef);
        }
        
        $this->persistAndUnload($chunkX, $chunkZ, $worldId);
    }

    /**
     * Enforce the loaded-chunk budget (11.3): while more than
     * $maxLoadedChunks chunks are resident, persist + drop the oldest ones
     * (FIFO by load order). Nothing is lost - every evicted chunk is written
     * to disk before it leaves memory, so a later loadChunk() reads it back
     * exactly as it was. Returns how many chunks were evicted.
     *
     * FIFO is the current eviction policy (cheap, deterministic, testable);
     * a distance-from-players policy would need player position tracking in
     * this service and can slot in behind the same call sites.
     */
    public function unloadUnusedChunks(int $maxLoadedChunks = 10000, int $worldId = 0): int {
        $store = $this->getChunkStore($worldId);
        if ($store === null) {
            return 0;
        }
        $evicted = 0;
        while ($store->getCount() > $maxLoadedChunks) {
            $oldest = $store->getOldestLoadedChunk();
            if ($oldest === null) {
                break; // store empty or inconsistent
            }
            [$chunkX, $chunkZ] = $oldest;
            $this->persistAndUnload($chunkX, $chunkZ, $worldId);
            $evicted++;
        }
        return $evicted;
    }

    private function persistAndUnload(int $chunkX, int $chunkZ, int $worldId = 0): void {
        // Persist and drop the chunk from the in-memory store.
        $store = $this->getChunkStore($worldId);
        if ($store !== null && $store->isLoaded($chunkX, $chunkZ)) {
            $chunkData = $store->toChunkData($chunkX, $chunkZ);
            if ($chunkData !== null) {
                // 14.16: never evict a chunk without its block-store tile
                // snapshots (chest contents, furnace state) - the kernel's
                // autosave attaches them, so eviction must too or a crash
                // after eviction loses block contents permanently.
                $chunkData = ChunkPersistence::attachTileSnapshots(
                    $this->world->getResourceRegistry(),
                    $chunkData,
                    $chunkX,
                    $chunkZ,
                );
                $this->getStorage($worldId)->saveChunk($chunkX, $chunkZ, $chunkData);
            }
            $store->unload($chunkX, $chunkZ);
        }
    }

    private function getChunkStore(int $worldId = 0): ?ChunkStore {
        // Non-default worlds resolve strictly through the registry; only the
        // default world (id 0) falls back to the classic resource-registry
        // store so single-world behavior is unchanged.
        if ($worldId !== 0) {
            $registry = $this->world->getResourceRegistry()->get(WorldRegistry::class);
            return $registry instanceof WorldRegistry ? $registry->getStore($worldId) : null;
        }
        $store = $this->world->getResourceRegistry()->get(ChunkStore::class);
        return $store instanceof ChunkStore ? $store : null;
    }

    private function getStorage(int $worldId = 0): StoragePort {
        // Only the default world may fall back to the kernel's own storage; an
        // unknown non-zero world must never read default-world data.
        if ($worldId !== 0) {
            $registry = $this->world->getResourceRegistry()->get(WorldRegistry::class);
            $storage = $registry instanceof WorldRegistry ? $registry->getStorage($worldId) : null;
            return $storage ?? $this->storagePort;
        }
        return $this->storagePort;
    }

    private function getEntitiesInChunk(int $chunkX, int $chunkZ, int $worldId = 0): array {
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
                $entities[] = \pocketmine\core\ecs\EntityRef::create($entity->id, $this->world);
            }
        }
        
        return $entities;
    }

    private function saveEntity(\pocketmine\core\ecs\EntityRef $entityRef): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;
        
        $metadata = $entity->get(\pocketmine\core\component\MetadataComponent::class);
        $uniqueId = $metadata?->get('uniqueId');
        
        if (!$uniqueId) return;
        
        $components = [];
        foreach ($entity->getComponents() as $type => $component) {
            $components[$type] = \pocketmine\core\ecs\ComponentSerializer::serialize($component);
        }
        
        $position = $entity->get(\pocketmine\core\component\PositionComponent::class);
        $rotation = $entity->get(\pocketmine\core\component\RotationComponent::class);
        
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

    public function migrateEntity(\pocketmine\core\ecs\EntityRef $entityRef, int $newChunkX, int $newChunkZ): void {
        // Entity moved to a new chunk - update chunk tracking
        // In a full implementation, this would update chunk-entity mappings
    }
}
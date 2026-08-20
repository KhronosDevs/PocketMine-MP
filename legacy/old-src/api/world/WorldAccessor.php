<?php

declare(strict_types=1);

namespace pocketmine\api\world;

use pocketmine\domain\ecs\EntityRef;
use pocketmine\domain\ecs\QueryBuilder;
use pocketmine\domain\ecs\World;
use pocketmine\domain\component\PositionComponent;
use pocketmine\domain\component\CollisionComponent;
use pocketmine\domain\component\MetadataComponent;

final class WorldAccessor {
    public function __construct(
        private readonly World $world,
    ) {}

    // Entity access
    public function getEntity(int $entityId): ?EntityRef {
        $entity = $this->world->getEntity($entityId);
        if (!$entity) return null;
        return EntityRef::create($entityId, $this->world);
    }

    public function getEntities(): array {
        $entities = [];
        foreach ($this->world->getEntities() as $entity) {
            $entities[] = EntityRef::create($entity->id, $this->world);
        }
        return $entities;
    }

    public function getPlayers(): array {
        $query = $this->world->query()
            ->with(MetadataComponent::class)
            ->withTag(\pocketmine\domain\component\tags\PlayerTag::class)
            ->build();
        
        $players = [];
        foreach ($query as $entity) {
            $players[] = EntityRef::create($entity->id, $this->world);
        }
        return $players;
    }

    public function getEntitiesInRadius(float $x, float $y, float $z, float $radius): array {
        $query = $this->world->query()
            ->with(PositionComponent::class)
            ->build();
        
        $entities = [];
        $radiusSq = $radius * $radius;
        
        foreach ($query as $entity) {
            $pos = $entity->get(PositionComponent::class);
            if (!$pos) continue;
            
            $dx = $pos->x - $x;
            $dy = $pos->y - $y;
            $dz = $pos->z - $z;
            $distSq = $dx * $dx + $dy * $dy + $dz * $dz;
            
            if ($distSq <= $radiusSq) {
                $entities[] = EntityRef::create($entity->id, $this->world);
            }
        }
        
        return $entities;
    }

    public function getEntitiesInChunk(int $chunkX, int $chunkZ): array {
        $query = $this->world->query()
            ->with(PositionComponent::class)
            ->build();
        
        $entities = [];
        $minX = $chunkX * 16;
        $maxX = $minX + 15;
        $minZ = $chunkZ * 16;
        $maxZ = $minZ + 15;
        
        foreach ($query as $entity) {
            $pos = $entity->get(PositionComponent::class);
            if (!$pos) continue;
            
            $entityChunkX = (int)floor($pos->x / 16);
            $entityChunkZ = (int)floor($pos->z / 16);
            
            if ($entityChunkX === $chunkX && $entityChunkZ === $chunkZ) {
                $entities[] = EntityRef::create($entity->id, $this->world);
            }
        }
        
        return $entities;
    }

    // Block access (would need chunk data)
    public function getBlock(int $x, int $y, int $z): ?int {
        // This would require chunk data access
        // For now, return null
        return null;
    }

    public function setBlock(int $x, int $y, int $z, int $blockId, int $meta = 0): bool {
        // This would require chunk data modification
        return false;
    }

    // Chunk access
    public function getChunk(int $chunkX, int $chunkZ): ?array {
        // This would require chunk data access
        return null;
    }

    public function isChunkLoaded(int $chunkX, int $chunkZ): bool {
        // Check if chunk is loaded
        return false;
    }

    // Query builder access
    public function query(): QueryBuilder {
        return $this->world->query();
    }
}
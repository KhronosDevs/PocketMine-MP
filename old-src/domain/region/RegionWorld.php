<?php

declare(strict_types=1);

namespace pocketmine\domain\region;

use pocketmine\domain\ecs\ComponentRegistry;
use pocketmine\domain\ecs\ResourceRegistry;
use pocketmine\domain\ecs\SystemScheduler;
use pocketmine\domain\ecs\World;
use pocketmine\domain\ecs\ThreadingPort;
use pocketmine\port\driven\ThreadingPort as ThreadingPortInterface;

final class RegionWorld extends World {
    private int $minChunkX;
    private int $maxChunkX;
    private int $minChunkZ;
    private int $maxChunkZ;
    private int $regionId;

    public function __construct(
        int $regionId,
        int $minChunkX,
        int $maxChunkX,
        int $minChunkZ,
        int $maxChunkZ,
        ComponentRegistry $componentRegistry,
        ResourceRegistry $resourceRegistry,
        SystemScheduler $systemScheduler,
    ) {
        parent::__construct($componentRegistry, $resourceRegistry, $systemScheduler);
        $this->regionId = $regionId;
        $this->minChunkX = $minChunkX;
        $this->maxChunkX = $maxChunkX;
        $this->minChunkZ = $minChunkZ;
        $this->maxChunkZ = $maxChunkZ;
    }

    public function getRegionId(): int {
        return $this->regionId;
    }

    public function getMinChunkX(): int {
        return $this->minChunkX;
    }

    public function getMaxChunkX(): int {
        return $this->maxChunkX;
    }

    public function getMinChunkZ(): int {
        return $this->minChunkZ;
    }

    public function getMaxChunkZ(): int {
        return $this->maxChunkZ;
    }

    public function ownsChunk(int $chunkX, int $chunkZ): bool {
        return $chunkX >= $this->minChunkX && $chunkX <= $this->maxChunkX
            && $chunkZ >= $this->minChunkZ && $chunkZ <= $this->maxChunkZ;
    }

    public function ownsEntity(\pocketmine\domain\ecs\EntityRef $entityRef): bool {
        $entity = $entityRef->getEntity();
        if (!$entity) return false;
        
        $position = $entity->get(\pocketmine\domain\component\PositionComponent::class);
        if (!$position) return false;
        
        $chunkX = (int)floor($position->x / 16);
        $chunkZ = (int)floor($position->z / 16);
        
        return $this->ownsChunk($chunkX, $chunkZ);
    }

    public function getBoundingBox(): array {
        return [
            'minX' => $this->minChunkX * 16,
            'maxX' => ($this->maxChunkX + 1) * 16,
            'minZ' => $this->minChunkZ * 16,
            'maxZ' => ($this->maxChunkZ + 1) * 16,
        ];
    }
}
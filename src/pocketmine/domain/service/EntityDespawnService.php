<?php

declare(strict_types=1);

namespace pocketmine\domain\service;

use pocketmine\domain\component\MetadataComponent;
use pocketmine\domain\component\PositionComponent;
use pocketmine\domain\ecs\EntityRef;
use pocketmine\domain\ecs\World;
use pocketmine\port\driven\StoragePort;

final class EntityDespawnService {
    public function __construct(
        private readonly World $world,
        private readonly StoragePort $storagePort,
    ) {}

    public function despawn(EntityRef $entityRef, bool $save = true): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;
        
        // Save entity data if requested
        if ($save) {
            $this->saveEntity($entityRef);
        }
        
        // Remove from world
        $this->world->despawn($entity);
    }

    public function despawnByDistance(EntityRef $playerRef, float $maxDistance = 128): void {
        $player = $playerRef->getEntity();
        if (!$player) return;
        
        $playerPos = $player->get(PositionComponent::class);
        if (!$playerPos) return;
        
        $query = $this->world->query()
            ->with(PositionComponent::class)
            ->with(MetadataComponent::class)
            ->build();
        
        foreach ($query as $entity) {
            // Skip players
            $meta = $entity->get(MetadataComponent::class);
            if ($meta && $meta->has('uniqueId')) {
                continue;
            }
            
            $position = $entity->get(PositionComponent::class);
            if (!$position) continue;
            
            $dx = $position->x - $playerPos->x;
            $dy = $position->y - $playerPos->y;
            $dz = $position->z - $playerPos->z;
            $distanceSq = $dx * $dx + $dy * $dy + $dz * $dz;
            
            if ($distanceSq > $maxDistance * $maxDistance) {
                $entityRef = \pocketmine\domain\ecs\EntityRef::create($entity->id, $this->world);
                $this->despawn($entityRef);
            }
        }
    }

    public function despawnInactiveEntities(int $maxInactiveTicks = 600): void {
        // Despawn entities that haven't been updated in a while
        // This would track last update time in metadata
    }

    private function saveEntity(EntityRef $entityRef): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;
        
        $meta = $entity->get(MetadataComponent::class);
        $uniqueId = $meta?->get('uniqueId');
        
        if (!$uniqueId) {
            // Generate unique ID for non-player entities
            $uniqueId = 'entity_' . $entity->id;
        }
        
        $components = [];
        foreach ($entity->getComponents() as $type => $component) {
            $components[$type] = \pocketmine\domain\ecs\ComponentSerializer::serialize($component);
        }
        
        $position = $entity->get(PositionComponent::class);
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
}
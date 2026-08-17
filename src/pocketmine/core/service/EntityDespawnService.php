<?php

declare(strict_types=1);

namespace pocketmine\core\service;

use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;
use pocketmine\port\driven\StoragePort;

final class EntityDespawnService {
    public function __construct(
        private readonly World $world,
        private readonly StoragePort $storagePort,
        private readonly ?\pocketmine\port\driving\EventPort $eventPort = null,
    ) {}

    public function despawn(EntityRef $entityRef, bool $save = true): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;
        
        // Blocker 4 audit: EntityDespawnEvent fires before the entity leaves
        // the world so plugins see it as still valid.
        if ($this->eventPort !== null) {
            $this->eventPort->emit(new \pocketmine\api\event\EntityDespawnEvent(
                \pocketmine\api\entity\Entity::wrap($entityRef, $this->world),
            ));

            // Events breadth audit: cancellable ItemDespawnEvent for dropped
            // items specifically - a plugin can keep an item in the world.
            if ($entity->has(\pocketmine\core\constants\EntityTags::ITEM)) {
                $itemEvent = new \pocketmine\api\event\ItemDespawnEvent(
                    \pocketmine\api\entity\Entity::wrap($entityRef, $this->world),
                );
                $this->eventPort->emit($itemEvent);
                if ($itemEvent->isCancelled()) {
                    return;
                }
            }
        }

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
            if ($meta && $meta->has(\pocketmine\core\constants\MetadataKeys::UNIQUE_ID)) {
                continue;
            }
            
            $position = $entity->get(PositionComponent::class);
            if (!$position) continue;
            
            $dx = $position->x - $playerPos->x;
            $dy = $position->y - $playerPos->y;
            $dz = $position->z - $playerPos->z;
            $distanceSq = $dx * $dx + $dy * $dy + $dz * $dz;
            
            if ($distanceSq > $maxDistance * $maxDistance) {
                $entityRef = \pocketmine\core\ecs\EntityRef::create($entity->id, $this->world);
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
        $uniqueId = $meta?->get(\pocketmine\core\constants\MetadataKeys::UNIQUE_ID);
        
        // Only entities with a REAL persistent id (players, named entities)
        // are saved. Ephemeral mobs get no file: nothing restores them yet,
        // and writing 'entity_N.dat' per despawn would accumulate dead files
        // forever (mob restore is a separate future feature).
        if (!$uniqueId) {
            return;
        }
        
        $components = [];
        foreach ($entity->getComponents() as $type => $component) {
            // String-keyed entries are boolean tag markers (withTag('item')),
            // not real components - skip them or serialization would choke.
            if (!is_object($component)) {
                continue;
            }
            $components[$type] = \pocketmine\core\ecs\ComponentSerializer::serialize($component);
        }
        
        $position = $entity->get(PositionComponent::class);
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
}
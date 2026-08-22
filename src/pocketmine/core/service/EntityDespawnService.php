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

    /**
     * Despawn every HOSTILE mob that is farther than $maxDistance from ALL
     * players at once. "All" is the load-bearing word: checking one player
     * per pass would despawn a mob standing next to player B just because it
     * is far from player A. Without this sweep the global hostile cap
     * eventually saturates with abandoned mobs nobody can reach, permanently
     * disabling MobSpawnerSystem.
     *
     * $players are the positions of the players that keep mobs alive (the
     * spawner passes its alive default-world set). Hostile-only by design:
     * passive animals and dropped items are not part of the spawn budget and
     * keep their own lifecycles.
     *
     * @param list<PositionComponent> $players
     * @return int how many mobs were queued for despawn (applied on the next
     *              world flush)
     */
    public function despawnFarFromAllPlayers(array $players, float $maxDistance = 128.0): int {
        if ($players === []) {
            return 0;
        }
        $maxSq = $maxDistance * $maxDistance;
        $victims = [];

        foreach ($this->world->query()
            ->with(PositionComponent::class, MetadataComponent::class)
            ->build() as $entity) {
            // Players never despawn by distance.
            if ($entity->has(\pocketmine\core\component\tags\PlayerTag::class)) {
                continue;
            }
            // Only default-world mobs are swept (the spawner populates that
            // world); other dimensions manage their own populations.
            $worldComponent = $entity->get(\pocketmine\core\component\WorldComponent::class);
            if ($worldComponent !== null && $worldComponent->id !== 0) {
                continue;
            }
            $meta = $entity->get(MetadataComponent::class);
            if ($meta === null || !$meta->get(\pocketmine\core\constants\MetadataKeys::HOSTILE)) {
                continue;
            }
            $pos = $entity->get(PositionComponent::class);
            if ($pos === null) {
                continue;
            }
            foreach ($players as $playerPos) {
                $dx = $pos->x - $playerPos->x;
                $dy = $pos->y - $playerPos->y;
                $dz = $pos->z - $playerPos->z;
                if ($dx * $dx + $dy * $dy + $dz * $dz <= $maxSq) {
                    continue 2; // near at least one player: stays
                }
            }
            $victims[] = $entity;
        }
        foreach ($victims as $entity) {
            // save=false: ephemeral hostile mobs carry no persistent unique id,
            // so there is nothing to write.
            $this->despawn(\pocketmine\core\ecs\EntityRef::create($entity->id, $this->world), false);
        }
        return count($victims);
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
<?php

declare(strict_types=1);

namespace pocketmine\core\service;

use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;
use pocketmine\port\driven\NetworkPort;
use pocketmine\port\driven\StoragePort;
use pocketmine\port\driven\PlayerRef;
use pocketmine\port\driving\EventPort;

final class PlayerLeaveService {
    public function __construct(
        private readonly World $world,
        private readonly NetworkPort $networkPort,
        private readonly StoragePort $storagePort,
        private readonly EventPort $eventPort,
    ) {}

    public function handleLeave(EntityRef $entityRef, string $reason = ""): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;
        
        // Blocker 4: PlayerLeaveEvent fires before the entity is saved and
        // despawned so plugins see the player as still valid.
        $this->eventPort->emit(new \pocketmine\api\event\PlayerLeaveEvent(
            $this->wrapApiPlayer($entityRef),
            $reason,
        ));
        // Events breadth audit: PlayerQuitEvent alongside the leave event
        // (the classic legacy name for the same moment).
        $this->eventPort->emit(new \pocketmine\api\event\PlayerQuitEvent(
            $this->wrapApiPlayer($entityRef),
            $reason,
        ));

        // Save player data before removing
        $this->savePlayer($entityRef);
        
        // Broadcast player removal to nearby players
        $this->broadcastPlayerLeave($entityRef);

        // Blocker 4 audit: EntityDespawnEvent fires as the entity leaves the
        // world (the leave path does not route through EntityDespawnService).
        $this->eventPort->emit(new \pocketmine\api\event\EntityDespawnEvent(
            $this->wrapApiPlayer($entityRef),
        ));
        
        // Despawn entity
        $this->world->despawn($entity);
    }

    private function wrapApiPlayer(EntityRef $ref): \pocketmine\api\entity\Player {
        $entity = \pocketmine\api\entity\Entity::wrap($ref, $this->world);
        return $entity instanceof \pocketmine\api\entity\Player
            ? $entity
            : new \pocketmine\api\entity\Player($ref, $this->world);
    }

    public function handleDisconnect(PlayerRef $playerRef, string $reason = ""): void {
        // Find entity by uniqueId
        $entityRef = $this->findEntityByUniqueId($playerRef->uniqueId);
        if ($entityRef) {
            $this->handleLeave($entityRef, $reason);
        }
    }

    private function findEntityByUniqueId(string $uniqueId): ?EntityRef {
        // withTag takes a COMPONENT class here (QueryBuilder has no tag-name
        // map like EntityBuilder): 'player' as a literal would match nothing
        // - real players carry the PlayerTag component class.
        $query = $this->world->query()
            ->with(\pocketmine\core\component\MetadataComponent::class)
            ->withTag(\pocketmine\core\component\tags\PlayerTag::class)
            ->build();
        
        foreach ($query as $entity) {
            $metadata = $entity->get(\pocketmine\core\component\MetadataComponent::class);
            if ($metadata && $metadata->get(\pocketmine\core\constants\MetadataKeys::UNIQUE_ID) === $uniqueId) {
                return \pocketmine\core\ecs\EntityRef::create($entity->id, $this->world);
            }
        }
        
        return null;
    }

    /**
     * Persist a player's position, health, inventory (+ held slot) and
     * metadata (username/uniqueId/settings) through the storage port.
     * Public so the kernel can flush online players on the autosave interval
     * and the session service can save them on shutdown.
     */
    public function savePlayer(EntityRef $entityRef): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;
        
        $metadata = $entity->get(\pocketmine\core\component\MetadataComponent::class);
        $uniqueId = $metadata?->get(\pocketmine\core\constants\MetadataKeys::UNIQUE_ID);
        
        if (!$uniqueId) return;
        
        // Explicit, restorable shape (NOT raw ComponentSerializer output - its
        // deserialize cannot rebuild object components like ItemStacks, so the
        // join path would read back broken data).
        $components = [];
        
        $health = $entity->get(\pocketmine\core\component\HealthComponent::class);
        if ($health !== null) {
            $components['health'] = ['current' => $health->current, 'max' => $health->max];
        }
        
        $inventory = $entity->get(\pocketmine\core\component\InventoryComponent::class);
        if ($inventory !== null) {
            $slots = [];
            foreach ($inventory->getContents() as $slot => $item) {
                if ($item instanceof \pocketmine\core\component\ItemStack) {
                    $slots[$slot] = $item->toArray();
                }
            }
            $components['inventory'] = ['slots' => $slots, 'heldSlot' => $inventory->heldSlot];
        }
        
        if ($metadata !== null) {
            $components['metadata'] = ['data' => $metadata->data];
        }
        
        $position = $entity->get(\pocketmine\core\component\PositionComponent::class);
        $rotation = $entity->get(\pocketmine\core\component\RotationComponent::class);
        
        $this->storagePort->saveEntity(new \pocketmine\port\driven\EntitySnapshot(
            $uniqueId,
            'Player',
            $position?->x ?? 0,
            $position?->y ?? 64,
            $position?->z ?? 0,
            $rotation?->yaw ?? 0,
            $rotation?->pitch ?? 0,
            $components
        ));
    }

    private function broadcastPlayerLeave(EntityRef $entityRef): void {
        // NetworkSyncSystem will handle sending RemoveEntityPacket
        // This service just triggers the cleanup
    }
}
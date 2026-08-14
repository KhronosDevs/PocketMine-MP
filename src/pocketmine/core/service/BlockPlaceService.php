<?php

declare(strict_types=1);

namespace pocketmine\core\service;

use pocketmine\core\component\CollisionComponent;
use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\RotationComponent;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;
use pocketmine\core\component\WorldComponent;
use pocketmine\core\resource\BlockRegistry;
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\resource\WorldRegistry;
use pocketmine\port\driving\EventPort;

final class BlockPlaceService {
    public function __construct(
        private readonly World $world,
        private readonly EventPort $eventPort,
    ) {}

    public function placeBlock(EntityRef $playerRef, int $x, int $y, int $z, int $face, int $blockId, int $meta = 0): bool {
        $player = $playerRef->getEntity();
        if (!$player) return false;

        $registry = $this->getBlockRegistry();
        
        // Check if player can reach the position
        if (!$this->canReach($playerRef, $x, $y, $z)) {
            return false;
        }
        
        // Check if player has the block in inventory
        if (!$this->hasBlockInInventory($playerRef, $blockId, $meta)) {
            return false;
        }
        
        // Check if placement is valid (not inside another block, etc.)
        if (!$this->isValidPlacement($x, $y, $z, $this->worldIdOf($playerRef))) {
            return false;
        }

        // Blocker 4: cancellable BlockPlaceEvent - a plugin can veto the
        // placement before the inventory item is consumed or the block set.
        $event = new \pocketmine\api\event\BlockPlaceEvent(
            $this->wrapApiPlayer($playerRef),
            $this->apiBlock($x, $y, $z, $this->worldIdOf($playerRef)),
            $face,
        );
        $this->eventPort->emit($event);
        if ($event->isCancelled()) {
            return false;
        }

        // Consume block from inventory
        $this->consumeBlock($playerRef, $blockId, $meta);
        
        // Resolve state meta (slab top/bottom from the placement face); the
        // inventory keeps the plain item meta (e.g. slab material 0-7).
        $placedMeta = $registry->applyPlacementMeta($blockId, $face, $meta);
        
        // Place the block
        $this->setBlock($x, $y, $z, $blockId, $placedMeta, $this->worldIdOf($playerRef));
        
        // Play place effects
        $this->playPlaceEffects($x, $y, $z, $blockId);
        
        return true;
    }

    private function canReach(EntityRef $playerRef, int $x, int $y, int $z): bool {
        $player = $playerRef->getEntity();
        if (!$player) return false;
        
        $position = $player->get(PositionComponent::class);
        if (!$position) return false;
        
        $collision = $player->get(CollisionComponent::class);
        $reach = $collision?->width ?? 3;
        if ($collision) {
            $reach = max(3, $collision->width * 2);
        }
        
        $dx = $x + 0.5 - $position->x;
        $dy = $y + 0.5 - $position->y;
        $dz = $z + 0.5 - $position->z;
        $distanceSq = $dx * $dx + $dy * $dy + $dz * $dz;
        
        return $distanceSq <= ($reach * $reach);
    }

    private function hasBlockInInventory(EntityRef $playerRef, int $blockId, int $meta): bool {
        $player = $playerRef->getEntity();
        if (!$player) return false;
        
        $inventory = $player->get(\pocketmine\core\component\InventoryComponent::class);
        if (!$inventory) return false;
        
        foreach ($inventory->getContents() as $item) {
            if ($item->itemId === $blockId && $item->meta === $meta && $item->count > 0) {
                return true;
            }
        }
        
        return false;
    }

    private function consumeBlock(EntityRef $playerRef, int $blockId, int $meta): void {
        $player = $playerRef->getEntity();
        if (!$player) return;
        
        $inventory = $player->get(\pocketmine\core\component\InventoryComponent::class);
        if (!$inventory) return;
        
        // Find and remove one
        foreach ($inventory->getContents() as $slot => $item) {
            if ($item->itemId === $blockId && $item->meta === $meta) {
                $inventory->remove($slot, 1);
                break;
            }
        }
    }

    private function isValidPlacement(int $x, int $y, int $z, int $worldId = 0): bool {
        $store = $this->getChunkStore($worldId);
        if ($store === null) {
            return false;
        }
        $existing = $store->getBlock($x, $y, $z);
        // A block may be placed only into air or a replaceable block
        // (tall grass, water, snow layers, etc.).
        return $existing === 0 || $this->getBlockRegistry()->isReplaceable($existing);
    }

    private function setBlock(int $x, int $y, int $z, int $blockId, int $meta, int $worldId = 0): void {
        $store = $this->getChunkStore($worldId);
        if ($store !== null) {
            $store->setBlock($x, $y, $z, $blockId, $meta);
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

    private function worldIdOf(EntityRef $playerRef): int {
        $entity = $playerRef->getEntity();
        $worldComponent = $entity?->get(WorldComponent::class);
        return $worldComponent instanceof WorldComponent ? $worldComponent->id : 0;
    }

    private function getBlockRegistry(): BlockRegistry {
        $registry = $this->world->getResourceRegistry()->get(BlockRegistry::class);
        return $registry instanceof BlockRegistry ? $registry : new BlockRegistry();
    }

    private function wrapApiPlayer(EntityRef $ref): \pocketmine\api\entity\Player {
        $entity = \pocketmine\api\entity\Entity::wrap($ref, $this->world);
        return $entity instanceof \pocketmine\api\entity\Player
            ? $entity
            : new \pocketmine\api\entity\Player($ref, $this->world);
    }

    /**
     * The API Block facade for a position in the player's world. Falls back
     * to the default world when the world id has no registered bundle.
     */
    private function apiBlock(int $x, int $y, int $z, int $worldId = 0): \pocketmine\api\block\Block {
        $server = \pocketmine\api\server\Server::getInstance();
        $apiWorld = $server->getWorldById($worldId) ?? $server->getDefaultWorld();
        return new \pocketmine\api\block\Block($apiWorld, $x, $y, $z);
    }

    private function playPlaceEffects(int $x, int $y, int $z, int $blockId): void {
        // NetworkSyncSystem would send LevelEventPacket for place sound/particles
    }
}
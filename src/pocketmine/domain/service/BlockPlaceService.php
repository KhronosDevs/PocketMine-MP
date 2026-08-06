<?php

declare(strict_types=1);

namespace pocketmine\domain\service;

use pocketmine\domain\component\CollisionComponent;
use pocketmine\domain\component\InventoryComponent;
use pocketmine\domain\component\MetadataComponent;
use pocketmine\domain\component\PositionComponent;
use pocketmine\domain\component\RotationComponent;
use pocketmine\domain\ecs\EntityRef;
use pocketmine\domain\ecs\World;

final class BlockPlaceService {
    public function __construct(
        private readonly World $world,
    ) {}

    public function placeBlock(EntityRef $playerRef, int $x, int $y, int $z, int $face, int $blockId, int $meta = 0): bool {
        $player = $playerRef->getEntity();
        if (!$player) return false;
        
        // Check if player can reach the position
        if (!$this->canReach($playerRef, $x, $y, $z)) {
            return false;
        }
        
        // Check if player has the block in inventory
        if (!$this->hasBlockInInventory($playerRef, $blockId, $meta)) {
            return false;
        }
        
        // Check if placement is valid (not inside another block, etc.)
        if (!$this->isValidPlacement($x, $y, $z)) {
            return false;
        }
        
        // Consume block from inventory
        $this->consumeBlock($playerRef, $blockId, $meta);
        
        // Place the block
        $this->setBlock($x, $y, $z, $blockId, $meta);
        
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
        
        $inventory = $player->get(\pocketmine\domain\component\InventoryComponent::class);
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
        
        $inventory = $player->get(\pocketmine\domain\component\InventoryComponent::class);
        if (!$inventory) return;
        
        // Find and remove one
        foreach ($inventory->getContents() as $slot => $item) {
            if ($item->itemId === $blockId && $item->meta === $meta) {
                $inventory->remove($slot, 1);
                break;
            }
        }
    }

    private function isValidPlacement(int $x, int $y, int $z): bool {
        // Check if target position is air
        // In a full implementation, this would check chunk data
        return true; // Simplified
    }

    private function setBlock(int $x, int $y, int $z, int $blockId, int $meta): void {
        // Set block in chunk data
        // This would modify the chunk's block data
    }

    private function playPlaceEffects(int $x, int $y, int $z, int $blockId): void {
        // NetworkSyncSystem would send LevelEventPacket for place sound/particles
    }
}
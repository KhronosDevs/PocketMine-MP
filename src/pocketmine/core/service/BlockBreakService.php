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
use pocketmine\port\driven\StoragePort;

final class BlockBreakService {
    public function __construct(
        private readonly World $world,
        private readonly StoragePort $storagePort,
    ) {}

    public function breakBlock(EntityRef $playerRef, int $x, int $y, int $z, int $face): bool {
        $player = $playerRef->getEntity();
        if (!$player) return false;
        
        // Check if player can reach the block
        if (!$this->canReach($playerRef, $x, $y, $z)) {
            return false;
        }
        
        // Check if block is breakable
        if (!$this->isBreakable($x, $y, $z)) {
            return false;
        }
        
        // Get tool from player's hand
        $tool = $this->getHeldItem($playerRef);
        
        // Calculate break speed
        $breakSpeed = $this->calculateBreakSpeed($tool, $x, $y, $z);
        
        // For instant break (creative mode), break immediately
        $metadata = $player->get(MetadataComponent::class);
        $gamemode = $metadata?->get('gamemode') ?? 0;
        
        if ($gamemode === 1) { // Creative
            return $this->doBreakBlock($playerRef, $x, $y, $z, $tool);
        }
        
        // Survival mode - would need progressive breaking
        // For now, instant break for testing
        return $this->doBreakBlock($playerRef, $x, $y, $z, $tool);
    }

    private function canReach(EntityRef $playerRef, int $x, int $y, int $z): bool {
        $player = $playerRef->getEntity();
        if (!$player) return false;
        
        $position = $player->get(PositionComponent::class);
        if (!$position) return false;
        
        $collision = $player->get(CollisionComponent::class);
        $reach = $collision?->width ?? 3; // Default reach ~3 blocks
        if ($collision) {
            $reach = max(3, $collision->width * 2);
        }
        
        $dx = $x + 0.5 - $position->x;
        $dy = $y + 0.5 - $position->y;
        $dz = $z + 0.5 - $position->z;
        $distanceSq = $dx * $dx + $dy * $dy + $dz * $dz;
        
        return $distanceSq <= ($reach * $reach);
    }

    private function isBreakable(int $x, int $y, int $z): bool {
        // Check if block at position is breakable
        // In a full implementation, this would query the chunk/block data
        return true; // Simplified
    }

    private function getHeldItem(EntityRef $playerRef): ?\pocketmine\core\component\ItemStack {
        $player = $playerRef->getEntity();
        if (!$player) return null;
        
        $inventory = $player->get(InventoryComponent::class);
        if (!$inventory) return null;
        
        $metadata = $player->get(MetadataComponent::class);
        $heldSlot = $metadata?->get('heldSlot') ?? 0;
        
        return $inventory->get($heldSlot);
    }

    private function calculateBreakSpeed(?\pocketmine\core\component\ItemStack $tool, int $x, int $y, int $z): float {
        // Calculate break speed based on tool and block
        // Simplified for now
        return 1.0;
    }

    private function doBreakBlock(EntityRef $playerRef, int $x, int $y, int $z, ?\pocketmine\core\component\ItemStack $tool): bool {
        // Get block drops
        $drops = $this->getBlockDrops($x, $y, $z, $tool);
        
        // Set block to air
        $this->setBlock($x, $y, $z, 0); // Air
        
        // Spawn drop entities
        foreach ($drops as $drop) {
            $this->spawnDropEntity($x + 0.5, $y + 0.5, $z + 0.5, $drop);
        }
        
        // Play break sound/particles
        $this->playBreakEffects($x, $y, $z);
        
        return true;
    }

    private function getBlockDrops(int $x, int $y, int $z, ?\pocketmine\core\component\ItemStack $tool): array {
        // Return item drops for the block
        // In a full implementation, this would use block registry
        return [];
    }

    private function spawnDropEntity(float $x, float $y, float $z, \pocketmine\core\component\ItemStack $item): void {
        $this->world->spawn(
            (new \pocketmine\core\ecs\EntityBuilder())
                ->with(new \pocketmine\core\component\PositionComponent($x, $y, $z))
                ->with(new \pocketmine\core\component\VelocityComponent(
                    (mt_rand(-10, 10) / 100),
                    0.2,
                    (mt_rand(-10, 10) / 100)
                ))
                ->with(new \pocketmine\core\component\HealthComponent(5, 5))
                ->with(new \pocketmine\core\component\InventoryComponent(1))
                ->with(new \pocketmine\core\component\MetadataComponent())
                ->withTag('item')
                
        );
        
        // Set the item in the entity's inventory
        // This would need to be done after spawn
    }

    private function playBreakEffects(int $x, int $y, int $z): void {
        // NetworkSyncSystem would send LevelEventPacket for break particles/sound
    }

    private function setBlock(int $x, int $y, int $z, int $blockId): void {
        // Set block in chunk data
        // This would modify the chunk's block data
    }
}
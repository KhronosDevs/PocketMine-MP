<?php

declare(strict_types=1);

namespace pocketmine\core\service;

use pocketmine\core\component\CollisionComponent;
use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\ItemStack;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\RotationComponent;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;
use pocketmine\core\resource\BlockRegistry;
use pocketmine\core\resource\ChunkStore;
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
        $store = $this->getChunkStore();
        if ($store === null) {
            return false;
        }
        $blockId = $store->getBlock($x, $y, $z);
        return $this->getBlockRegistry()->isBreakable($blockId);
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

    private function calculateBreakSpeed(?ItemStack $tool, int $x, int $y, int $z): float {
        $store = $this->getChunkStore();
        if ($store === null) {
            return 0.0;
        }
        $blockId = $store->getBlock($x, $y, $z);
        $registry = $this->getBlockRegistry();

        $hardness = $registry->getHardness($blockId);
        if ($hardness < 0.0) {
            return 0.0; // unbreakable
        }
        if ($hardness === 0.0) {
            return 10.0; // instant (grass, torches, etc.)
        }

        // Correct tool tier mines faster; wrong tool mines very slowly.
        $requiredTool = $registry->getToolType($blockId);
        $toolType = $tool !== null ? $this->toolTypeForItem($tool->itemId) : 'hand';
        $speed = 1.0 / $hardness;

        if ($toolType === $requiredTool) {
            $toolLevel = $registry->getToolLevel($blockId);
            $speed *= $toolLevel <= 0 ? 2.0 : 3.0 + $toolLevel; // correct tool
        } elseif ($registry->requiresTool($blockId)) {
            $speed *= 0.2; // wrong tool: five times slower
        }

        return max(0.05, $speed);
    }

    private function doBreakBlock(EntityRef $playerRef, int $x, int $y, int $z, ?ItemStack $tool): bool {
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

    /**
     * @return ItemStack[]
     */
    private function getBlockDrops(int $x, int $y, int $z, ?ItemStack $tool): array {
        $store = $this->getChunkStore();
        if ($store === null) {
            return [];
        }
        $blockId = $store->getBlock($x, $y, $z);

        $silkTouch = false;
        $toolType = $tool !== null ? $this->toolTypeForItem($tool->itemId) : null;
        if ($toolType === 'shears') {
            $silkTouch = true;
        }

        $drops = $this->getBlockRegistry()->getDrops($blockId, $silkTouch, $toolType);
        $items = [];
        foreach ($drops as $drop) {
            $items[] = new ItemStack($drop['id'], $drop['meta'], $drop['count']);
        }
        return $items;
    }

    private function toolTypeForItem(int $itemId): string {
        return match (true) {
            $itemId >= 256 && $itemId <= 259 => 'sword',
            $itemId >= 269 && $itemId <= 271 => 'shovel',
            $itemId >= 273 && $itemId <= 275 => 'pickaxe',
            $itemId >= 277 && $itemId <= 279 => 'axe',
            $itemId >= 284 && $itemId <= 286 => 'shears',
            default => 'hand',
        };
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
        $store = $this->getChunkStore();
        if ($store !== null) {
            $store->setBlock($x, $y, $z, $blockId, 0);
        }
    }

    private function getChunkStore(): ?ChunkStore {
        $store = $this->world->getResourceRegistry()->get(ChunkStore::class);
        return $store instanceof ChunkStore ? $store : null;
    }

    private function getBlockRegistry(): BlockRegistry {
        $registry = $this->world->getResourceRegistry()->get(BlockRegistry::class);
        return $registry instanceof BlockRegistry ? $registry : new BlockRegistry();
    }
}
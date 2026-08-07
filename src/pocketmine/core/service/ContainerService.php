<?php

declare(strict_types=1);

namespace pocketmine\core\service;

use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\ItemStack;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;

final class ContainerService {
    public function __construct(
        private readonly World $world,
    ) {}

    public function openContainer(EntityRef $playerRef, EntityRef $containerRef): bool {
        $player = $playerRef->getEntity();
        $container = $containerRef->getEntity();
        
        if (!$player || !$container) return false;
        
        // Set container metadata on player
        $playerMeta = $player->get(MetadataComponent::class);
        if (!$playerMeta) return false;
        
        $playerMeta->set('openContainer', $containerRef->getId());
        $playerMeta->set('containerType', $this->getContainerType($containerRef));
        
        // Send ContainerOpenPacket via NetworkSyncSystem
        return true;
    }

    public function closeContainer(EntityRef $playerRef): void {
        $player = $playerRef->getEntity();
        if (!$player) return;
        
        $playerMeta = $player->get(MetadataComponent::class);
        if (!$playerMeta) return;
        
        $playerMeta->remove('openContainer');
        $playerMeta->remove('containerType');
        
        // Send ContainerClosePacket via NetworkSyncSystem
    }

    public function setContainerSlot(EntityRef $playerRef, int $slot, ?ItemStack $item): void {
        $player = $playerRef->getEntity();
        if (!$player) return;
        
        $playerMeta = $player->get(MetadataComponent::class);
        if (!$playerMeta) return;
        
        $containerId = $playerMeta->get('openContainer');
        if (!$containerId) return;
        
        $containerRef = \pocketmine\core\ecs\EntityRef::create($containerId, $this->world);
        $container = $containerRef->getEntity();
        if (!$container) return;
        
        $containerInventory = $container->get(InventoryComponent::class);
        if (!$containerInventory) return;
        
        $containerInventory->set($slot, $item);
        
        // Send ContainerSetSlotPacket via NetworkSyncSystem
    }

    public function getContainerSlot(EntityRef $playerRef, int $slot): ?ItemStack {
        $player = $playerRef->getEntity();
        if (!$player) return null;
        
        $playerMeta = $player->get(MetadataComponent::class);
        if (!$playerMeta) return null;
        
        $containerId = $playerMeta->get('openContainer');
        if (!$containerId) return null;
        
        $containerRef = \pocketmine\core\ecs\EntityRef::create($containerId, $this->world);
        $container = $containerRef->getEntity();
        if (!$container) return null;
        
        $containerInventory = $container->get(InventoryComponent::class);
        if (!$containerInventory) return null;
        
        return $containerInventory->get($slot);
    }

    public function transferItem(EntityRef $playerRef, int $fromSlot, int $toSlot, bool $toPlayerInventory = false): bool {
        $player = $playerRef->getEntity();
        if (!$player) return false;
        
        $playerMeta = $player->get(MetadataComponent::class);
        if (!$playerMeta) return false;
        
        $containerId = $playerMeta->get('openContainer');
        if (!$containerId) return false;
        
        $containerRef = \pocketmine\core\ecs\EntityRef::create($containerId, $this->world);
        $container = $containerRef->getEntity();
        if (!$container) return false;
        
        $containerInventory = $container->get(InventoryComponent::class);
        $playerInventory = $player->get(\pocketmine\core\component\InventoryComponent::class);
        
        if (!$containerInventory || !$playerInventory) return false;
        
        if ($toPlayerInventory) {
            // Move from container to player inventory
            $item = $containerInventory->get($fromSlot);
            if (!$item) return false;
            
            // Find space in player inventory
            if ($this->addToPlayerInventory($playerInventory, $item)) {
                $containerInventory->remove($fromSlot);
                return true;
            }
        } else {
            // Move from player inventory to container
            $item = $playerInventory->get($fromSlot);
            if (!$item) return false;
            
            // Find space in container
            for ($i = 0; $i < $containerInventory->size; $i++) {
                if (!$containerInventory->get($i)) {
                    $containerInventory->set($i, $item);
                    $playerInventory->remove($fromSlot);
                    return true;
                }
                
                // Try stacking
                $existing = $containerInventory->get($i);
                if ($existing && $existing->canStackWith($item)) {
                    $space = $existing->getMaxStackSize() - $existing->count;
                    $transfer = min($space, $item->count);
                    $existing->count += $transfer;
                    $item->count -= $transfer;
                    if ($item->count <= 0) {
                        $playerInventory->remove($fromSlot);
                        return true;
                    }
                }
            }
        }
        
        return false;
    }

    private function addToPlayerInventory(InventoryComponent $playerInventory, ItemStack $item): bool {
        // Try to stack first
        foreach ($playerInventory->getContents() as $existing) {
            if ($existing->canStackWith($item)) {
                $space = $existing->getMaxStackSize() - $existing->count;
                $transfer = min($space, $item->count);
                $existing->count += $transfer;
                $item->count -= $transfer;
                if ($item->count <= 0) return true;
            }
        }
        
        // Find empty slot
        for ($i = 0; $i < $playerInventory->size; $i++) {
            if (!$playerInventory->get($i)) {
                $playerInventory->set($i, $item);
                return true;
            }
        }
        
        return false;
    }

    private function getContainerType(EntityRef $containerRef): string {
        $container = $containerRef->getEntity();
        if (!$container) return 'chest';
        
        $meta = $container->get(MetadataComponent::class);
        if (!$meta) return 'chest';
        
        return $meta->get('containerType') ?? 'chest';
    }
}
<?php

declare(strict_types=1);

namespace pocketmine\core\service;

use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\ItemStack;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;

final class InventoryService {
    public function __construct(
        private readonly World $world,
    ) {}

    public function addItem(EntityRef $entityRef, ItemStack $item): bool {
        $entity = $entityRef->getEntity();
        if (!$entity) return false;
        
        $inventory = $entity->get(InventoryComponent::class);
        if (!$inventory) return false;
        
        return $inventory->add($item);
    }

    public function removeItem(EntityRef $entityRef, int $slot, int $count = 1): ?ItemStack {
        $entity = $entityRef->getEntity();
        if (!$entity) return null;
        
        $inventory = $entity->get(InventoryComponent::class);
        if (!$inventory) return null;
        
        return $inventory->remove($slot, $count);
    }

    public function getItem(EntityRef $entityRef, int $slot): ?ItemStack {
        $entity = $entityRef->getEntity();
        if (!$entity) return null;
        
        $inventory = $entity->get(InventoryComponent::class);
        if (!$inventory) return null;
        
        return $inventory->get($slot);
    }

    public function setItem(EntityRef $entityRef, int $slot, ?ItemStack $item): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;
        
        $inventory = $entity->get(InventoryComponent::class);
        if (!$inventory) return;
        
        $inventory->set($slot, $item);
    }

    public function swapItems(EntityRef $entityRef, int $slot1, int $slot2): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;
        
        $inventory = $entity->get(InventoryComponent::class);
        if (!$inventory) return;
        
        $item1 = $inventory->get($slot1);
        $item2 = $inventory->get($slot2);
        
        $inventory->set($slot1, $item2);
        $inventory->set($slot2, $item1);
    }

    public function clearInventory(EntityRef $entityRef): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;
        
        $inventory = $entity->get(InventoryComponent::class);
        if (!$inventory) return;
        
        $inventory->clear();
    }

    public function getInventoryContents(EntityRef $entityRef): array {
        $entity = $entityRef->getEntity();
        if (!$entity) return [];
        
        $inventory = $entity->get(InventoryComponent::class);
        if (!$inventory) return [];
        
        return $inventory->getContents();
    }

    public function setInventoryContents(EntityRef $entityRef, array $items): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;
        
        $inventory = $entity->get(InventoryComponent::class);
        if (!$inventory) return;
        
        $inventory->setContents($items);
    }

    public function getHeldItem(EntityRef $entityRef): ?ItemStack {
        $entity = $entityRef->getEntity();
        if (!$entity) return null;
        
        $metadata = $entity->get(MetadataComponent::class);
        $heldSlot = $metadata?->get('heldSlot') ?? 0;
        
        return $this->getItem($entityRef, $heldSlot);
    }

    public function setHeldSlot(EntityRef $entityRef, int $slot): void {
        $entity = $entityRef->getEntity();
        if (!$entity) return;
        
        $metadata = $entity->get(MetadataComponent::class);
        if (!$metadata) return;
        
        if ($slot >= 0 && $slot < 9) { // Hotbar slots 0-8
            $metadata->set('heldSlot', $slot);
        }
    }

    public function getFreeSlots(EntityRef $entityRef): int {
        $entity = $entityRef->getEntity();
        if (!$entity) return 0;
        
        $inventory = $entity->get(InventoryComponent::class);
        if (!$inventory) return 0;
        
        $free = 0;
        for ($i = 0; $i < $inventory->size; $i++) {
            if (!$inventory->get($i)) {
                $free++;
            }
        }
        return $free;
    }

    public function canAddItem(EntityRef $entityRef, ItemStack $item): bool {
        $entity = $entityRef->getEntity();
        if (!$entity) return false;
        
        $inventory = $entity->get(InventoryComponent::class);
        if (!$inventory) return false;
        
        // Check if can stack with existing
        foreach ($inventory->getContents() as $existing) {
            if ($existing->canStackWith($item)) {
                $space = $existing->getMaxStackSize() - $existing->count;
                if ($space > 0) return true;
            }
        }
        
        // Check for empty slots
        return $this->getFreeSlots($entityRef) > 0;
    }
}
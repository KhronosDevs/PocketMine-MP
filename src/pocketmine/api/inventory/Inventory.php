<?php

declare(strict_types=1);

namespace pocketmine\api\inventory;

use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\ItemStack;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;
use pocketmine\api\entity\Player;

class Inventory {
    private InventoryComponent $inventory;
    private EntityRef $holder;
    private World $world;

    public function __construct(EntityRef $holder, World $world) {
        $this->holder = $holder;
        $this->world = $world;
        
        $inventory = $holder->getInventory();
        if (!$inventory) {
            $inventory = new \pocketmine\core\component\InventoryComponent();
            $holder->setComponent(\pocketmine\core\component\InventoryComponent::class, $inventory);
        }
        $this->inventory = $inventory;
    }

    public function getSize(): int {
        return $this->inventory->size;
    }

    public function getItem(int $slot): ?ItemStack {
        return $this->inventory->get($slot);
    }

    public function setItem(int $slot, ?ItemStack $item): void {
        $this->inventory->set($slot, $item);
    }

    public function addItem(ItemStack $item): bool {
        return $this->inventory->add($item);
    }

    public function removeItem(int $slot, int $count = 1): ?ItemStack {
        return $this->inventory->remove($slot, $count);
    }

    public function clear(): void {
        $this->inventory->clear();
    }

    public function getContents(): array {
        return $this->inventory->getContents();
    }

    public function setContents(array $items): void {
        $this->inventory->setContents($items);
    }

    public function getHeldItem(): ?ItemStack {
        return $this->getItem(0); // Simplified - would use held slot
    }

    public function setHeldSlot(int $slot): void {
        $metadata = $this->holder->getMetadata();
        if ($metadata) {
            $metadata->set('heldSlot', $slot);
        }
    }

    public function getHeldSlot(): int {
        $metadata = $this->holder->getMetadata();
        return $metadata?->get('heldSlot', 0) ?? 0;
    }

    public function getFreeSlots(): int {
        $free = 0;
        for ($i = 0; $i < $this->inventory->size; $i++) {
            if (!$this->inventory->get($i)) {
                $free++;
            }
        }
        return $free;
    }

    public function canAddItem(ItemStack $item): bool {
        return $this->canAddItem($item);
    }

    public function getFirstFreeSlot(): int {
        for ($i = 0; $i < $this->inventory->size; $i++) {
            if (!$this->inventory->get($i)) {
                return $i;
            }
        }
        return -1;
    }

    public function contains(ItemStack $item): bool {
        foreach ($this->inventory->getContents() as $existing) {
            if ($existing->canStackWith($item)) {
                return true;
            }
        }
        return false;
    }

    public function countItem(int $itemId, int $meta = 0): int {
        $count = 0;
        foreach ($this->inventory->getContents() as $item) {
            if ($item->itemId === $itemId && $item->meta === $meta) {
                $count += $item->count;
            }
        }
        return $count;
    }

    public function removeItemById(int $itemId, int $count = 1, int $meta = 0): int {
        $removed = 0;
        for ($i = 0; $i < $this->inventory->size; $i++) {
            $item = $this->inventory->get($i);
            if ($item && $item->itemId === $itemId && $item->meta === $meta) {
                $removeCount = min($count - $removed, $item->count);
                $item->count -= $removeCount;
                $removed += $removeCount;
                if ($item->count <= 0) {
                    $this->inventory->remove($i);
                }
                if ($removed >= $count) {
                    break;
                }
            }
        }
        return $removed;
    }
}
<?php

declare(strict_types=1);

namespace pocketmine\api\inventory;

use pocketmine\core\component\InventoryComponent;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;

/**
 * Plugin-facing inventory facade over the core InventoryComponent.
 *
 * The facade speaks api\inventory\ItemStack exclusively and converts to the
 * core storage type (core\component\ItemStack) at the boundary, so plugins
 * never touch core component types.
 *
 * Note: getItem()/getContents() return detached api ItemStack copies - mutate
 * them and write back via setItem()/setContents(); the underlying storage is
 * only written through the facade.
 */
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
        $item = $this->inventory->get($slot);
        return $item === null ? null : ItemStack::fromCore($item);
    }

    public function setItem(int $slot, ?ItemStack $item): void {
        $this->inventory->set($slot, $item === null ? null : $item->toCore());
    }

    public function addItem(ItemStack $item): bool {
        $core = $item->toCore();
        $ok = $this->inventory->add($core);
        // Reflect what was actually consumed back onto the caller's stack.
        $item->setCount($core->count);
        return $ok;
    }

    public function removeItem(int $slot, int $count = 1): ?ItemStack {
        $removed = $this->inventory->remove($slot, $count);
        return $removed === null ? null : ItemStack::fromCore($removed);
    }

    public function clear(): void {
        $this->inventory->clear();
    }

    /**
     * @return array<int, ItemStack>
     */
    public function getContents(): array {
        $items = [];
        foreach ($this->inventory->getContents() as $slot => $item) {
            $items[$slot] = ItemStack::fromCore($item);
        }
        return $items;
    }

    /**
     * @param array<int, ItemStack|null> $items
     */
    public function setContents(array $items): void {
        $coreItems = [];
        foreach ($items as $slot => $item) {
            $coreItems[$slot] = $item === null ? null : $item->toCore();
        }
        $this->inventory->setContents($coreItems);
    }

    public function getHeldItem(): ?ItemStack {
        return $this->getItem($this->getHeldSlot());
    }

    public function setHeldSlot(int $slot): void {
        $this->inventory->setHeldSlot($slot);
    }

    public function getHeldSlot(): int {
        return $this->inventory->heldSlot;
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
        $core = $item->toCore();
        if ($core->count <= 0) {
            return true;
        }
        $remaining = $core->count;
        // First, fit what we can into existing stacks of the same item.
        foreach ($this->inventory->getContents() as $existing) {
            if ($existing->canStackWith($core)) {
                $remaining -= $existing->getMaxStackSize() - $existing->count;
                if ($remaining <= 0) {
                    return true;
                }
            }
        }
        // Any remainder needs empty slots (a full stack per slot).
        return $this->getFreeSlots() * $core->getMaxStackSize() >= $remaining;
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
        $core = $item->toCore();
        foreach ($this->inventory->getContents() as $existing) {
            if ($existing->canStackWith($core)) {
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

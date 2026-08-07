<?php

declare(strict_types=1);

namespace pocketmine\core\component;

use pocketmine\core\ecs\Component;

#[Component]
final class InventoryComponent {
    /** @var array<int, ItemStack> */
    public array $slots = [];
    public int $size;
    public int $heldSlot = 0;

    public function __construct(int $size = 36) {
        $this->size = $size;
    }

    public function get(int $slot): ?ItemStack {
        return $this->slots[$slot] ?? null;
    }

    /**
     * Set which slot is held. Validated against the inventory size so callers
     * (API facade + services) share one policy and cannot point the held slot
     * outside the inventory. Returns false when out of range.
     */
    public function setHeldSlot(int $slot): bool {
        if ($slot < 0 || $slot >= $this->size) {
            return false;
        }
        $this->heldSlot = $slot;
        return true;
    }

    public function set(int $slot, ?ItemStack $item): void {
        if ($item === null) {
            unset($this->slots[$slot]);
        } else {
            $this->slots[$slot] = $item;
        }
    }

    public function add(ItemStack $item): bool {
        // Try to stack first
        foreach ($this->slots as $slot => $existing) {
            if ($existing->canStackWith($item)) {
                $added = min($existing->getMaxStackSize() - $existing->count, $item->count);
                $existing->count += $added;
                $item->count -= $added;
                if ($item->count <= 0) {
                    return true;
                }
            }
        }

        // Find empty slot
        for ($i = 0; $i < $this->size; $i++) {
            if (!isset($this->slots[$i])) {
                $this->slots[$i] = $item;
                return true;
            }
        }
        return false;
    }

    public function remove(int $slot, int $count = 1): ?ItemStack {
        if (!isset($this->slots[$slot])) {
            return null;
        }
        $item = $this->slots[$slot];
        if ($item->count <= $count) {
            unset($this->slots[$slot]);
            return $item;
        }
        $item->count -= $count;
        return new ItemStack($item->itemId, $item->meta, $count, $item->nbt);
    }

    public function clear(): void {
        $this->slots = [];
    }

    public function getContents(): array {
        return $this->slots;
    }

    public function setContents(array $items): void {
        $this->slots = [];
        foreach ($items as $slot => $item) {
            if ($item !== null && $slot >= 0 && $slot < $this->size) {
                $this->slots[$slot] = $item;
            }
        }
    }

    public function toArray(): array {
        $result = [];
        foreach ($this->slots as $slot => $item) {
            $result[$slot] = $item->toArray();
        }
        return $result;
    }
}

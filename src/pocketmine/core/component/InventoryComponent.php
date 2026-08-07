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

final class ItemStack {
    public function __construct(
        public int $itemId,
        public int $meta = 0,
        public int $count = 1,
        public ?array $nbt = null,
    ) {}

    public function canStackWith(ItemStack $other): bool {
        return $this->itemId === $other->itemId &&
               $this->meta === $other->meta &&
               $this->nbt === $other->nbt;
    }

    public function getMaxStackSize(): int {
        // TODO: Look up from item registry
        return 64;
    }

    public function toArray(): array {
        return [
            'id' => $this->itemId,
            'meta' => $this->meta,
            'count' => $this->count,
            'nbt' => $this->nbt,
        ];
    }

    public static function fromArray(array $data): self {
        return new self(
            $data['id'],
            $data['meta'] ?? 0,
            $data['count'] ?? 1,
            $data['nbt'] ?? null,
        );
    }
}
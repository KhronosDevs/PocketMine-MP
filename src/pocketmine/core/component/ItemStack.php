<?php

declare(strict_types=1);

namespace pocketmine\core\component;

use pocketmine\core\ecs\Component;

#[Component]
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

    // --- Enchantments (14.30) -------------------------------------------------
    //
    // Enchantments live in the item's NBT as the legacy 'ench' list: each
    // entry is ['id' => int, 'lvl' => int]. The same shape is used for both
    // the server-authoritative copy and the wire NBT, so the client renders
    // enchanted items correctly without a separate conversion step.

    /** @return list<array{id: int, lvl: int}> */
    public function getEnchantments(): array {
        $ench = $this->nbt['ench'] ?? null;
        if (!is_array($ench)) {
            return [];
        }
        $out = [];
        foreach ($ench as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = (int)($entry['id'] ?? 0);
            $lvl = (int)($entry['lvl'] ?? 1);
            if ($id > 0 && $lvl > 0) {
                $out[] = ['id' => $id, 'lvl' => $lvl];
            }
        }
        return $out;
    }

    public function hasEnchantments(): bool {
        return $this->getEnchantments() !== [];
    }

    /** Level of an enchantment on this item (0 = not present). */
    public function getEnchantmentLevel(int $enchantmentId): int {
        foreach ($this->getEnchantments() as $entry) {
            if ($entry['id'] === $enchantmentId) {
                return $entry['lvl'];
            }
        }
        return 0;
    }

    /** Add (or raise) an enchantment. Returns a new instance. */
    public function withEnchantment(int $enchantmentId, int $level): self {
        $ench = $this->getEnchantments();
        $found = false;
        foreach ($ench as $i => $entry) {
            if ($entry['id'] === $enchantmentId) {
                $ench[$i]['lvl'] = max($level, $entry['lvl']);
                $found = true;
                break;
            }
        }
        if (!$found) {
            $ench[] = ['id' => $enchantmentId, 'lvl' => $level];
        }
        $nbt = $this->nbt ?? [];
        $nbt['ench'] = $ench;
        return new self($this->itemId, $this->meta, $this->count, $nbt);
    }

    /** Clear all enchantments. Returns a new instance. */
    public function withoutEnchantments(): self {
        if ($this->nbt === null) {
            return $this;
        }
        $nbt = $this->nbt;
        unset($nbt['ench']);
        return new self($this->itemId, $this->meta, $this->count, $nbt);
    }

    /** Custom display name (the NBT 'display' -> 'Name' entry), or null. */
    public function getCustomName(): ?string {
        $display = $this->nbt['display'] ?? null;
        $name = is_array($display) ? ($display['Name'] ?? null) : null;
        return is_string($name) && $name !== '' ? $name : null;
    }

    /** Returns a copy with a custom display name (anvil rename). */
    public function withCustomName(string $name): self {
        $nbt = $this->nbt ?? [];
        $display = is_array($nbt['display'] ?? null) ? $nbt['display'] : [];
        $display['Name'] = $name;
        $nbt['display'] = $display;
        return new self($this->itemId, $this->meta, $this->count, $nbt);
    }

    /**
     * Repair cost (anvil): legacy items carry it in NBT as 'RepairCost'.
     * Newly enchanted items start at 0; every anvil use adds 1 on top of the
     * inherited cost of the inputs.
     */
    public function getRepairCost(): int {
        return (int)($this->nbt['RepairCost'] ?? 0);
    }

    public function withRepairCost(int $cost): self {
        $nbt = $this->nbt ?? [];
        $nbt['RepairCost'] = $cost;
        return new self($this->itemId, $this->meta, $this->count, $nbt);
    }

    private static ?\pocketmine\core\resource\ItemRegistry $itemRegistry = null;

    public function getMaxStackSize(): int {
        self::$itemRegistry ??= \pocketmine\Kernel::getInstance()?->getResourceRegistry()
            ->get(\pocketmine\core\resource\ItemRegistry::class);
        return self::$itemRegistry instanceof \pocketmine\core\resource\ItemRegistry
            ? self::$itemRegistry->getMaxStackSize($this->itemId)
            : 64;
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

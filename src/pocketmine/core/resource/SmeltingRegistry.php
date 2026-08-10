<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

use pocketmine\core\component\ItemStack;
use pocketmine\core\ecs\Resource;

/**
 * Furnace smelting + fuel registry (14.16).
 *
 * Smelting recipes: an input item (id, optional meta wildcard -1) maps to an
 * output ItemStack; cook time is the vanilla 200 ticks (10 s at 20 TPS).
 * Fuel: item id (+ optional meta) maps to burn ticks. Data mirrors the legacy
 * 0.15 CraftingManager::registerFurnace entries (recipes.json type 2/3) and
 * the legacy Fuel::$duration table exactly.
 */
#[Resource]
final class SmeltingRegistry {
    /** Vanilla smelting duration: 10 seconds at 20 TPS. */
    public const COOK_TICKS = 200;

    /**
     * @var array<string, array{input: ItemStack, result: ItemStack}> key = "id:meta"
     */
    private array $recipes = [];

    /** @var array<string, int> key = "id:meta" => burn ticks */
    private array $fuel = [];

    /**
     * Register a smelting recipe. A meta of -1 matches any input meta.
     */
    public function registerSmelting(ItemStack $input, ItemStack $result): void {
        $this->recipes[$this->key($input->itemId, $input->meta)] = [
            'input' => $input,
            'result' => $result,
        ];
    }

    /**
     * Register a burnable fuel. A meta of -1 matches any input meta.
     */
    public function registerFuel(int $itemId, int $meta, int $burnTicks): void {
        $this->fuel[$this->key($itemId, $meta)] = $burnTicks;
    }

    /**
     * Find the smelting recipe for an input item (exact meta first, then
     * wildcard). Returns the result ItemStack or null if not smeltable.
     */
    public function matchSmelting(ItemStack $input): ?ItemStack {
        $exact = $this->recipes[$this->key($input->itemId, $input->meta)] ?? null;
        if ($exact !== null) {
            return clone $exact['result'];
        }
        $wild = $this->recipes[$this->key($input->itemId, -1)] ?? null;
        return $wild !== null ? clone $wild['result'] : null;
    }

    /**
     * Burn ticks for a fuel item, or 0 if it is not burnable.
     */
    public function getFuelTicks(ItemStack $fuel): int {
        if ($fuel->count <= 0) {
            return 0;
        }
        $exact = $this->fuel[$this->key($fuel->itemId, $fuel->meta)] ?? null;
        if ($exact !== null) {
            return $exact;
        }
        return $this->fuel[$this->key($fuel->itemId, -1)] ?? 0;
    }

    private function key(int $id, int $meta): string {
        return $id . ':' . $meta;
    }
}

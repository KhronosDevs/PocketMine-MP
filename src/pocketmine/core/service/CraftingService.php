<?php

declare(strict_types=1);

namespace pocketmine\core\service;

use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\ItemStack;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;
use pocketmine\core\resource\RecipeRegistry;

final class CraftingService {
    public function __construct(
        private readonly World $world,
    ) {}

    /**
     * Attempt a craft.
     *
     * @param list<ItemStack|null> $grid flat row-major crafting grid
     * @param int $gridWidth 2 for the player 2x2, 3 for a crafting table
     * @return ItemStack|null the crafted result (already added to the player's
     *                        inventory), or null when the recipe does not
     *                        match / ingredients are missing / no inventory space
     */
    public function craft(EntityRef $playerRef, array $grid, int $gridWidth = 2): ?ItemStack {
        $player = $playerRef->getEntity();
        if (!$player) return null;

        // 3x3 grids require a crafting table (metadata flag set on use).
        if ($gridWidth === 3) {
            $metadata = $player->get(MetadataComponent::class);
            if (($metadata?->get('craftingTable') ?? false) !== true) {
                return null;
            }
        }

        $recipes = $this->world->getResourceRegistry()->get(RecipeRegistry::class);
        if (!$recipes instanceof RecipeRegistry) {
            return null;
        }

        $recipe = $recipes->matchShaped($grid, $gridWidth);
        if ($recipe === null) {
            return null;
        }

        $inventory = $player->get(InventoryComponent::class);
        if (!$inventory) return null;

        // Verify the player actually holds every grid ingredient. The grid is
        // consumed one item per occupied cell, so each cell must hold count 1
        // (a stacked cell is not a valid crafting input).
        $required = $this->countGridIngredients($grid);
        if (!$this->inventoryHas($inventory, $required)) {
            return null;
        }

        // Clone the registered result: add() mutates the stack's count on the
        // stacking path, which would corrupt the registry's stored recipe and
        // hand the caller a result with a residual count.
        $src = $recipe['result'];
        $result = new ItemStack($src->itemId, $src->meta, $src->count, $src->nbt);
        // The crafted item must fit BEFORE anything is consumed, so a failed
        // craft never mutates the inventory (atomic check-then-commit).
        if (!$inventory->canAddItem($result)) {
            return null;
        }

        // Consume exactly one of each occupied grid cell.
        $this->consumeIngredients($inventory, $grid);

        // Now guaranteed to fit. add() mutates the passed stack's count down to
        // 0 on the stacking path, so return a fresh copy of the pristine
        // source rather than the consumed stack.
        $inventory->add($result);
        return new ItemStack($src->itemId, $src->meta, $src->count, $src->nbt);
    }

    /**
     * Count how many of each item id/meta the grid requires - one per occupied
     * cell, matching consumeIngredients (a cell holding a stack > 1 is not a
     * valid crafting input and is counted as a single required unit).
     *
     * @param list<ItemStack|null> $grid
     * @return array<string, int> "id:meta" => count
     */
    private function countGridIngredients(array $grid): array {
        $required = [];
        foreach ($grid as $item) {
            if ($item !== null && $item->count > 0) {
                $key = $item->itemId . ':' . $item->meta;
                $required[$key] = ($required[$key] ?? 0) + 1;
            }
        }
        return $required;
    }

    /**
     * @param array<string, int> $required "id:meta" => count
     */
    private function inventoryHas(InventoryComponent $inventory, array $required): bool {
        foreach ($required as $key => $count) {
            [$id, $meta] = explode(':', $key);
            $found = 0;
            foreach ($inventory->getContents() as $item) {
                if ($item->itemId === (int)$id && $item->meta === (int)$meta) {
                    $found += $item->count;
                }
            }
            if ($found < $count) {
                return false;
            }
        }
        return true;
    }

    /**
     * Remove one of each occupied grid item from the inventory.
     *
     * @param list<ItemStack|null> $grid
     */
    private function consumeIngredients(InventoryComponent $inventory, array $grid): void {
        foreach ($grid as $item) {
            if ($item === null || $item->count <= 0) {
                continue;
            }
            foreach ($inventory->getContents() as $slot => $invItem) {
                if ($invItem->itemId === $item->itemId && $invItem->meta === $item->meta) {
                    $inventory->remove($slot, 1);
                    break;
                }
            }
        }
    }
}

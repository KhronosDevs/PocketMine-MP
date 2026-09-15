<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

use pocketmine\core\component\ItemStack;
use pocketmine\core\ecs\Resource;

/**
 * Crafting recipe registry (12.3).
 *
 * Shaped recipes: a pattern grid with character keys mapping to items.
 * Shapeless recipes: an unordered ingredient multiset (any grid placement
 * that contains exactly those ingredients matches).
 * Recipes live in registry data (populated by Kernel::registerBuiltinRecipes
 * and extendable by plugins via registerShaped/registerShapeless) instead of
 * being hard-coded in the crafting service.
 */
#[Resource]
final class RecipeRegistry {
    /**
     * @var array<string, array{pattern: list<string>, key: array<string, ItemStack>, result: ItemStack}>
     */
    private array $shaped = [];

    /**
     * @var array<string, array{ingredients: list<ItemStack>, result: ItemStack}>
     */
    private array $shapeless = [];

    /**
     * Register a shaped recipe.
     *
     * @param list<string> $pattern e.g. ['WWW', ' S ', ' S ']
     * @param array<string, ItemStack> $key char => ingredient
     */
    public function registerShaped(string $id, array $pattern, array $key, ItemStack $result): void {
        $this->shaped[$id] = [
            'pattern' => $pattern,
            'key' => $key,
            'result' => $result,
        ];
    }

    /**
     * Register a shapeless recipe: the grid must contain exactly the given
     * ingredients (as a multiset, ignoring placement). An ingredient meta of
     * -1 matches any meta of that id.
     *
     * @param list<ItemStack> $ingredients
     */
    public function registerShapeless(string $id, array $ingredients, ItemStack $result): void {
        $this->shapeless[$id] = [
            'ingredients' => $ingredients,
            'result' => $result,
        ];
    }

    /**
     * All registered shaped recipes, keyed by recipe id.
     *
     * @return array<string, array{pattern: list<string>, key: array<string, ItemStack>, result: ItemStack}>
     */
    public function getShapedRecipes(): array {
        return $this->shaped;
    }

    /**
     * All registered shapeless recipes, keyed by recipe id.
     *
     * @return array<string, array{ingredients: list<ItemStack>, result: ItemStack}>
     */
    public function getShapelessRecipes(): array {
        return $this->shapeless;
    }

    /**
     * Find a shapeless recipe matching the grid. The multiset of grid items
     * (id:meta) must equal the recipe's ingredient multiset exactly - no
     * extra items, none missing. Wildcard ingredient meta (-1) matches any
     * meta; the grid's actual metas are what get consumed.
     *
     * @param list<ItemStack|null> $grid
     * @return array{ingredients: list<ItemStack>, result: ItemStack, matched: list<ItemStack>}|null
     *         matched = the concrete grid stacks that satisfied each ingredient     * (in ingredient order), so the caller consumes real metas.
     */
    public function matchShapeless(array $grid): ?array {
        // Multiset of grid items, grouped by id so wildcard meta lookups can
        // walk the available metas of that id.
        $gridByItem = [];
        foreach ($grid as $item) {
            if ($item === null || $item->count <= 0) {
                continue;
            }
            // A stacked cell is not a valid crafting input (same rule as
            // shaped): each occupied cell supplies one unit.
            $gridByItem[$item->itemId][] = $item->meta;
        }
        $gridTotal = 0;
        foreach ($gridByItem as $metas) {
            $gridTotal += count($metas);
        }
        if ($gridTotal === 0) {
            return null;
        }

        foreach ($this->shapeless as $recipe) {
            $ingredients = $recipe['ingredients'];
            if (count($ingredients) !== $gridTotal) {
                continue; // wrong ingredient count - multiset can never match
            }
            // Greedy consume: each ingredient takes an available grid entry.
            $available = $gridByItem;
            $matched = [];
            $ok = true;
            foreach ($ingredients as $ingredient) {
                $found = null;
                if (isset($available[$ingredient->itemId])) {
                    foreach ($available[$ingredient->itemId] as $idx => $meta) {
                        if ($ingredient->meta === -1 || $meta === $ingredient->meta) {
                            $found = $idx;
                            break;
                        }
                    }
                    if ($found !== null) {
                        $matched[] = new ItemStack($ingredient->itemId, $available[$ingredient->itemId][$found], 1, null);
                        unset($available[$ingredient->itemId][$found]);
                        continue;
                    }
                }
                $ok = false;
                break;
            }
            if ($ok) {
                return [
                    'ingredients' => $ingredients,
                    'result' => $recipe['result'],
                    'matched' => $matched,
                ];
            }
        }
        return null;
    }

    /**
     * Find a shaped recipe matching the given crafting grid.
     *
     * The grid is a flat list (row-major) of ItemStack|null entries. Like
     * vanilla Minecraft, a recipe whose pattern is smaller than the grid may
     * be placed at ANY offset inside the grid, so every offset where the
     * pattern fits is tried. A 2x2 recipe therefore matches inside a 3x3
     * crafting table whether placed in the top-left or bottom-right corner.
     *
     * @param list<ItemStack|null> $grid
     * @return array{pattern: list<string>, key: array<string, ItemStack>, result: ItemStack}|null
     */
    public function matchShaped(array $grid, int $gridWidth): ?array {
        $rows = (int)ceil(count($grid) / max(1, $gridWidth));
        foreach ($this->shaped as $recipe) {
            $patternRows = count($recipe['pattern']);
            $patternWidth = max(array_map('strlen', $recipe['pattern']));
            if ($patternRows > $rows || $patternWidth > $gridWidth) {
                continue;
            }
            $maxRowOff = $rows - $patternRows;
            $maxColOff = $gridWidth - $patternWidth;
            for ($rowOff = 0; $rowOff <= $maxRowOff; $rowOff++) {
                for ($colOff = 0; $colOff <= $maxColOff; $colOff++) {
                    if ($this->gridMatches($grid, $gridWidth, $recipe['pattern'], $recipe['key'], $rowOff, $colOff)) {
                        return $recipe;
                    }
                }
            }
        }
        return null;
    }

    /**
     * @param list<ItemStack|null> $grid
     * @param array<string, ItemStack> $key
     */
    private function gridMatches(array $grid, int $gridWidth, array $pattern, array $key, int $rowOff, int $colOff): bool {
        foreach ($pattern as $row => $patternRow) {
            foreach (str_split($patternRow) as $col => $char) {
                $slot = ($rowOff + $row) * $gridWidth + ($colOff + $col);
                $item = $grid[$slot] ?? null;
                if ($char === ' ') {
                    if ($item !== null) {
                        return false;
                    }
                    continue;
                }
                $ingredient = $key[$char] ?? null;
                if ($ingredient === null) {
                    return false;
                }
                if ($item === null) {
                    return false;
                }
                // Wildcard meta (-1) matches any; otherwise exact match.
                if ($item->itemId !== $ingredient->itemId) {
                    return false;
                }
                if ($ingredient->meta !== -1 && $item->meta !== $ingredient->meta) {
                    return false;
                }
            }
        }

        // The grid must contain EXACTLY the recipe: every cell outside the
        // pattern's bounding box (at this offset) must be empty. Without this,
        // a 1x1 recipe matches a grid with extra junk in the remaining slots.
        $patternRows = count($pattern);
        $patternWidth = max(array_map('strlen', $pattern));
        $gridRows = (int)ceil(count($grid) / max(1, $gridWidth));
        for ($row = 0; $row < $gridRows; $row++) {
            for ($col = 0; $col < $gridWidth; $col++) {
                if ($row >= $rowOff && $row < $rowOff + $patternRows &&
                    $col >= $colOff && $col < $colOff + $patternWidth) {
                    continue; // inside the pattern - already checked
                }
                $slot = $row * $gridWidth + $col;
                if (($grid[$slot] ?? null) !== null) {
                    return false;
                }
            }
        }
        return true;
    }
}

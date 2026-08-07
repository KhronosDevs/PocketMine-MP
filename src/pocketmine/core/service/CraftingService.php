<?php

declare(strict_types=1);

namespace pocketmine\core\service;

use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\ItemStack;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;

final class CraftingService {
    public function __construct(
        private readonly World $world,
    ) {}

    public function craft(EntityRef $playerRef, array $grid, int $gridSize = 3): ?ItemStack {
        $player = $playerRef->getEntity();
        if (!$player) return null;
        
        // Check if player has crafting table for 3x3
        $metadata = $player->get(\pocketmine\core\component\MetadataComponent::class);
        $hasCraftingTable = $metadata?->get('craftingTable') ?? false;
        
        if ($gridSize === 3 && !$hasCraftingTable) {
            return null; // Need crafting table for 3x3
        }
        
        // Find matching recipe
        $recipe = $this->findRecipe($grid, $gridSize);
        if (!$recipe) return null;
        
        // Check if player has ingredients
        if (!$this->hasIngredients($playerRef, $grid, $recipe)) {
            return null;
        }
        
        // Consume ingredients
        $this->consumeIngredients($playerRef, $grid, $recipe);
        
        // Create result
        $result = new ItemStack(
            $recipe['result']['id'],
            $recipe['result']['meta'] ?? 0,
            $recipe['result']['count'] ?? 1
        );
        
        // Add to inventory
        $inventory = $this->world->getComponentRegistry()->get(InventoryComponent::class);
        // This would use InventoryService
        
        return $result;
    }

    private function findRecipe(array $grid, int $gridSize): ?array {
        $recipes = [
            // 2x2 crafting
            'pickaxe_wood' => [
                'pattern' => ['WWW', ' S ', ' S '],
                'key' => ['W' => ['id' => 5, 'meta' => 0], 'S' => ['id' => 280, 'meta' => 0]],
                'result' => ['id' => 270, 'count' => 1], // Wooden pickaxe
                'size' => 3,
            ],
            // ... more recipes
        ];

        foreach ($recipes as $recipe) {
            if ($recipe['size'] !== $gridSize) {
                continue;
            }
            if ($this->gridMatches($grid, $recipe)) {
                return $recipe;
            }
        }

        return null;
    }

    private function gridMatches(array $grid, array $recipe): bool {
        $width = strlen($recipe['pattern'][0] ?? '');
        foreach ($recipe['pattern'] as $row => $patternRow) {
            foreach (str_split($patternRow) as $col => $ch) {
                $slot = $row * $width + $col;
                $item = $grid[$slot] ?? null;
                if ($ch === ' ') {
                    if ($item !== null) {
                        return false;
                    }
                    continue;
                }
                $key = $recipe['key'][$ch] ?? null;
                if ($key === null || $item === null) {
                    return false;
                }
                if (($item['id'] ?? null) !== $key['id']) {
                    return false;
                }
                if (($item['meta'] ?? 0) !== ($key['meta'] ?? 0)) {
                    return false;
                }
            }
        }
        return true;
    }

    private function hasIngredients(EntityRef $playerRef, array $grid, array $recipe): bool {
        $player = \pocketmine\core\ecs\EntityRef::create($playerRef->getId(), $this->world)->getEntity();
        if (!$player) return false;
        
        $inventory = $player->get(\pocketmine\core\component\InventoryComponent::class);
        if (!$inventory) return false;
        
        // Count required items
        $required = [];
        foreach ($grid as $slot => $item) {
            if ($item) {
                $key = $item['id'] . ':' . $item['meta'];
                $required[$key] = ($required[$key] ?? 0) + $item['count'];
            }
        }
        
        // Check inventory
        foreach ($required as $key => $count) {
            [$id, $meta] = explode(':', $key);
            $found = 0;
            
            foreach ($inventory->getContents() as $item) {
                if ($item->itemId == (int)$id && $item->meta == (int)$meta) {
                    $found += $item->count;
                }
            }
            
            if ($found < $count) return false;
        }
        
        return true;
    }

    private function consumeIngredients(EntityRef $playerRef, array $grid, array $recipe): void {
        $player = \pocketmine\core\ecs\EntityRef::create($playerRef->getId(), $this->world)->getEntity();
        if (!$player) return;
        
        $inventory = $player->get(\pocketmine\core\component\InventoryComponent::class);
        if (!$inventory) return;
        
        // Count and remove items from grid positions
        foreach ($grid as $item) {
            if ($item) {
                // Find and remove one
                foreach ($inventory->getContents() as $slot => $invItem) {
                    if ($invItem->itemId === $item['id'] && $invItem->meta === $item['meta']) {
                        $inventory->remove($slot, 1);
                        break;
                    }
                }
            }
        }
    }
}
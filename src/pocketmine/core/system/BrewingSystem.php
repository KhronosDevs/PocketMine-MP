<?php

declare(strict_types=1);

namespace pocketmine\core\system;

use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\ItemStack;
use pocketmine\core\ecs\System;
use pocketmine\core\ecs\World;
use pocketmine\core\resource\BrewingRegistry;
use pocketmine\core\resource\BrewingStore;
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\resource\WorldRegistry;
use pocketmine\core\service\NetworkSessionService;

/**
 * Brewing stand ticking (14.27) - runs every world tick on the main thread.
 *
 * Mirrors the legacy BrewingStand::onUpdate: while the ingredient slot holds a
 * valid ingredient AND at least one bottle slot (1-3) holds a potion that the
 * ingredient brews, the brew timer counts down from MAX_BREW_TIME (400 ticks
 * = 20 s). When it reaches zero every matching bottle converts to its recipe
 * result and one ingredient is consumed. Without a valid combination the
 * timer resets to MAX_BREW_TIME.
 *
 * The gunpowder transform switches the potion item id 373 -> 438 (splash);
 * every other transform keeps the input item id (373 stays 373, 438 stays
 * 438) and only changes the potion meta.
 */
final class BrewingSystem implements System {
    /** Legacy BrewingStand::MAX_BREW_TIME. */
    public const MAX_BREW_TIME = 400;

    public function run(World $world, float $deltaTime): void {
        $resources = $world->getResourceRegistry();
        $recipes = $resources->get(BrewingRegistry::class);
        if (!$recipes instanceof BrewingRegistry) {
            return;
        }
        $network = \pocketmine\Kernel::getInstance()?->getNetworkSessionService();
        if (!$network instanceof NetworkSessionService) {
            $network = null;
        }

        $registry = $resources->get(WorldRegistry::class);
        if ($registry instanceof WorldRegistry) {
            foreach ($registry->getWorlds() as $worldId => $_) {
                $store = $registry->getBrewingStore((int)$worldId);
                if ($store instanceof BrewingStore) {
                    $this->tickStands($store, $recipes, $network, (int)$worldId);
                }
            }
        } else {
            $store = $resources->get(BrewingStore::class);
            if ($store instanceof BrewingStore) {
                $this->tickStands($store, $recipes, $network, 0);
            }
        }
    }

    private function tickStands(BrewingStore $store, BrewingRegistry $recipes, ?NetworkSessionService $network, int $worldId): void {
        foreach ($store->getAll() as $key => $state) {
            [$x, $y, $z] = array_map('intval', explode(':', $key));
            /** @var InventoryComponent $inv */
            $inv = $state['inventory'];
            $ingredient = $inv->get(BrewingStore::SLOT_INGREDIENT);
            $canBrew = $ingredient !== null && $ingredient->count > 0 && $this->anyBottleMatches($inv, $recipes, $ingredient);

            if ($canBrew) {
                // Legacy decrements CookTime each brew tick.
                $state['brewTime']--;
                if ($state['brewTime'] <= 0) {
                    $state['brewTime'] = self::MAX_BREW_TIME;
                    $this->convertBottles($inv, $recipes, $ingredient);
                    $this->consumeIngredient($inv, $ingredient);
                }
            } else {
                // No valid combo: timer idles at max.
                $state['brewTime'] = self::MAX_BREW_TIME;
            }
            $store->put($x, $y, $z, $state);
            // Sync slot state (bottles + ingredient) to viewers. On a brew
            // completion the slots actually changed; on an idle stand this is
            // a no-op for viewers (contents identical), so the cheap full
            // resync only fires when something moved.
            $network?->syncContainer('brewing', $x, $y, $z, null, $worldId);
        }
    }

    private function anyBottleMatches(InventoryComponent $inv, BrewingRegistry $recipes, ItemStack $ingredient): bool {
        for ($slot = BrewingStore::SLOT_BOTTLE_1; $slot <= BrewingStore::SLOT_BOTTLE_3; $slot++) {
            $potion = $inv->get($slot);
            if ($potion === null) {
                continue;
            }
            if ($potion->itemId !== BrewingRegistry::ITEM_POTION && $potion->itemId !== BrewingRegistry::ITEM_SPLASH_POTION) {
                continue;
            }
            if ($recipes->match($ingredient->itemId, $ingredient->meta, $potion->meta) !== null) {
                return true;
            }
        }
        return false;
    }

    private function convertBottles(InventoryComponent $inv, BrewingRegistry $recipes, ItemStack $ingredient): void {
        for ($slot = BrewingStore::SLOT_BOTTLE_1; $slot <= BrewingStore::SLOT_BOTTLE_3; $slot++) {
            $potion = $inv->get($slot);
            if ($potion === null) {
                continue;
            }
            if ($potion->itemId !== BrewingRegistry::ITEM_POTION && $potion->itemId !== BrewingRegistry::ITEM_SPLASH_POTION) {
                continue;
            }
            $outputMeta = $recipes->match($ingredient->itemId, $ingredient->meta, $potion->meta);
            if ($outputMeta === null) {
                continue;
            }
            // Gunpowder converts a drinkable potion into its splash variant;
            // every other recipe keeps the input item id and changes meta.
            $outputId = ($ingredient->itemId === BrewingRegistry::ING_GUNPOWDER)
                ? BrewingRegistry::ITEM_SPLASH_POTION
                : $potion->itemId;
            $inv->set($slot, new ItemStack($outputId, $outputMeta, $potion->count, $potion->nbt));
        }
    }

    private function consumeIngredient(InventoryComponent $inv, ItemStack $ingredient): void {
        $count = $ingredient->count - 1;
        $inv->set(BrewingStore::SLOT_INGREDIENT, $count > 0 ? new ItemStack($ingredient->itemId, $ingredient->meta, $count, $ingredient->nbt) : null);
    }
}

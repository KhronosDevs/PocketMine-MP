<?php

declare(strict_types=1);

namespace pocketmine\core\system;

use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\ItemStack;
use pocketmine\core\ecs\System;
use pocketmine\core\ecs\World;
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\resource\FurnaceStore;
use pocketmine\core\resource\SmeltingRegistry;
use pocketmine\core\resource\WorldRegistry;
use pocketmine\core\service\NetworkSessionService;

/**
 * Furnace ticking (14.16) - runs every world tick on the main thread.
 *
 * Each furnace with state in the FurnaceStore is advanced exactly like the
 * legacy Furnace::onUpdate: burn the fuel slot, accumulate cook time while a
 * valid smelting recipe matches the input and the result slot has room,
 * produce one result every 200 ticks, and flip the block between the lit
 * (62) and unlit (61) furnace ids. Slot + block-state changes are synced to
 * every session with that furnace open through the network service.
 */
final class FurnaceSystem implements System {
    public const BLOCK_FURNACE = 61;
    public const BLOCK_LIT_FURNACE = 62;

    public function run(World $world, float $deltaTime): void {
        $resources = $world->getResourceRegistry();
        $smelting = $resources->get(SmeltingRegistry::class);
        if (!$smelting instanceof SmeltingRegistry) {
            return;
        }
        $network = \pocketmine\Kernel::getInstance()?->getNetworkSessionService();
        if (!$network instanceof NetworkSessionService) {
            $network = null;
        }

        // Furnace state is per-world (like chests): tick every bundle's store
        // with that world's ChunkStore for block-state flips and pass the
        // world id through to the network sync. The default world (id 0)
        // falls back to the global resource instances.
        $registry = $resources->get(WorldRegistry::class);
        if ($registry instanceof WorldRegistry) {
            foreach ($registry->getWorlds() as $worldId => $_) {
                $store = $registry->getFurnaceStore((int)$worldId);
                $chunks = $registry->getStore((int)$worldId);
                if ($store instanceof FurnaceStore) {
                    $this->tickFurnaces($store, $chunks, $smelting, $network, (int)$worldId);
                }
            }
        } else {
            // Registry-less path (tests constructing the kernel bare): the
            // classic single global store, world 0.
            $store = $resources->get(FurnaceStore::class);
            if ($store instanceof FurnaceStore) {
                $this->tickFurnaces($store, $resources->get(ChunkStore::class), $smelting, $network, 0);
            }
        }
    }

    /** Advance every furnace in one world's store. */
    private function tickFurnaces(FurnaceStore $store, ?ChunkStore $chunks, SmeltingRegistry $smelting, ?NetworkSessionService $network, int $worldId): void {
        foreach ($store->getAll() as $key => $state) {
            [$x, $y, $z] = array_map('intval', explode(':', $key));
            /** @var InventoryComponent $inv */
            $inv = $state['inventory'];
            $input = $inv->get(FurnaceStore::SLOT_SMELTING);
            $fuel = $inv->get(FurnaceStore::SLOT_FUEL);
            $result = $inv->get(FurnaceStore::SLOT_RESULT);
            $recipeResult = $input !== null ? $smelting->matchSmelting($input) : null;
            // A result slot is ready when it is empty or can stack one more
            // of the recipe's output.
            $resultReady = $recipeResult !== null && $this->resultCanAccept($result, $recipeResult);

            $changed = false;
            // Light the furnace when fuel runs low and smelting is possible.
            // Note: the lighting tick does not decrement burnTime (the fuel's
            // full burn duration is spent from the NEXT tick on, matching the
            // store round-trip the tests assert).
            if ($state['burnTime'] <= 0) {
                if ($resultReady && $fuel !== null) {
                    $ticks = $smelting->getFuelTicks($fuel);
                    if ($ticks > 0) {
                        $state['burnTime'] = $ticks;
                        $state['cookTime'] = 0;
                        $this->consumeOne($inv, FurnaceStore::SLOT_FUEL, $fuel);
                        $this->setBlockState($chunks, $x, $y, $z, self::BLOCK_LIT_FURNACE);
                        $changed = true;
                        $network?->syncFurnace($x, $y, $z, FurnaceStore::SLOT_FUEL, true, $worldId);
                    }
                }
            } else {
                $state['burnTime']--;
                $changed = true;
                if ($resultReady) {
                    $state['cookTime']++;
                    if ($state['cookTime'] >= SmeltingRegistry::COOK_TICKS) {
                        $state['cookTime'] -= SmeltingRegistry::COOK_TICKS;
                        $this->consumeOne($inv, FurnaceStore::SLOT_SMELTING, $input);
                        $this->produceResult($inv, $result, $recipeResult);
                        $network?->syncFurnace($x, $y, $z, null, false, $worldId);
                    }
                } elseif ($state['cookTime'] > 0) {
                    // Input changed / recipe no longer valid: progress resets.
                    $state['cookTime'] = 0;
                }
                if ($state['burnTime'] <= 0) {
                    $this->setBlockState($chunks, $x, $y, $z, self::BLOCK_FURNACE);
                    $network?->syncFurnace($x, $y, $z, null, true, $worldId);
                }
            }
            // Only rewrite the store entry when something actually moved (an
            // idle furnace with no fuel/input is a no-op every tick).
            if ($changed) {
                $store->put($x, $y, $z, $state);
            }
        }
    }

    private function resultCanAccept(?ItemStack $result, ItemStack $recipeResult): bool {
        if ($result === null) {
            return true;
        }
        return $result->canStackWith($recipeResult)
            && $result->count < $result->getMaxStackSize();
    }

    private function consumeOne(InventoryComponent $inv, int $slot, ItemStack $stack): void {
        $count = $stack->count - 1;
        $inv->set($slot, $count > 0 ? new ItemStack($stack->itemId, $stack->meta, $count, $stack->nbt) : null);
    }

    private function produceResult(InventoryComponent $inv, ?ItemStack $result, ItemStack $recipeResult): void {
        if ($result === null) {
            $inv->set(FurnaceStore::SLOT_RESULT, clone $recipeResult);
        } else {
            $inv->set(
                FurnaceStore::SLOT_RESULT,
                new ItemStack($recipeResult->itemId, $recipeResult->meta, $result->count + 1, $result->nbt),
            );
        }
    }

    private function setBlockState(?ChunkStore $chunks, int $x, int $y, int $z, int $id): void {
        if ($chunks !== null && $chunks->getBlock($x, $y, $z) !== $id) {
            $chunks->setBlock($x, $y, $z, $id);
        }
    }
}

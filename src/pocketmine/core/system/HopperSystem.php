<?php

declare(strict_types=1);

namespace pocketmine\core\system;

use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\ItemStack;
use pocketmine\core\ecs\System;
use pocketmine\core\ecs\World;
use pocketmine\core\resource\BrewingStore;
use pocketmine\core\resource\ChestStore;
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\resource\ContainerLookup;
use pocketmine\core\resource\ContainerStore;
use pocketmine\core\resource\FurnaceStore;
use pocketmine\core\resource\WorldRegistry;
use pocketmine\core\service\NetworkSessionService;

/**
 * Hopper ticking (14.27) - runs every world tick on the main thread.
 *
 * Mirrors the legacy Hopper::onUpdate cadence: contents transfer on every
 * 8th tick. Each transfer moves ONE item:
 *   - pull: the first occupied slot of the container block directly above
 *     the hopper moves into the hopper (legacy getSide(SIDE_UP));
 *   - push: the first item in the hopper moves into the container below it
 *     (legacy getSide(damage), which is DOWN for a floor hopper).
 *
 * Containers are resolved through ContainerLookup by block id, so hoppers
 * work with chests, furnaces, dispensers, other hoppers and brewing stands.
 * Changed slots are synced to viewers with the container window open through
 * the network service.
 */
final class HopperSystem implements System {
    /** Legacy Hopper::canUpdate - contents transfer every 8 ticks. */
    public const TRANSFER_INTERVAL = 8;

    /** Own tick phase so the cadence works under world->tick() tests too. */
    private int $tick = 0;

    public function run(World $world, float $deltaTime): void {
        $resources = $world->getResourceRegistry();
        $network = \pocketmine\Kernel::getInstance()?->getNetworkSessionService();
        if (!$network instanceof NetworkSessionService) {
            $network = null;
        }

        // Only transfer on the cadence tick (every 8th run). An own counter
        // is used rather than the world-global TickCounter, which is advanced
        // by the kernel loop (not by world->tick()) and would make hoppers
        // transfer every tick in tests that drive the world directly.
        $this->tick++;
        if ($this->tick % self::TRANSFER_INTERVAL !== 0) {
            return;
        }

        $registry = $resources->get(WorldRegistry::class);
        if ($registry instanceof WorldRegistry) {
            foreach ($registry->getWorlds() as $worldId => $_) {
                $this->tickHoppers(
                    $registry->getStore((int)$worldId),
                    $registry->getChestStore((int)$worldId),
                    $registry->getFurnaceStore((int)$worldId),
                    $registry->getContainerStore((int)$worldId),
                    $registry->getBrewingStore((int)$worldId),
                    $network,
                    (int)$worldId,
                );
            }
        } else {
            $this->tickHoppers(
                $resources->get(ChunkStore::class),
                $resources->get(ChestStore::class),
                $resources->get(FurnaceStore::class),
                $resources->get(ContainerStore::class),
                $resources->get(BrewingStore::class),
                $network,
                0,
            );
        }
    }

    private function tickHoppers(
        ?ChunkStore $chunks,
        ?ChestStore $chests,
        ?FurnaceStore $furnaces,
        ?ContainerStore $containers,
        ?BrewingStore $brewing,
        ?NetworkSessionService $network,
        int $worldId,
    ): void {
        if (!$containers instanceof ContainerStore || !$chests instanceof ChestStore
            || !$furnaces instanceof FurnaceStore || !$brewing instanceof BrewingStore) {
            return;
        }

        // Snapshot the hopper keys first (transfers may create new store
        // entries, e.g. a chest get() below the hopper - iterating the live
        // map while mutating would be unsafe).
        $keys = [];
        $prefix = ContainerStore::TYPE_HOPPER . ':';
        foreach ($containers->all() as $key => $inv) {
            if (str_starts_with($key, $prefix)) {
                $keys[] = $key;
            }
        }
        foreach ($keys as $key) {
            $tokens = explode(':', $key);
            [$x, $y, $z] = [(int)$tokens[1], (int)$tokens[2], (int)$tokens[3]];
            $hopper = $containers->get(ContainerStore::TYPE_HOPPER, $x, $y, $z);
            $changed = $this->pullFromAbove($chunks, $chests, $furnaces, $containers, $brewing, $hopper, $x, $y, $z);
            // Push: first hopper item -> container below (skip when the block
            // directly below is another hopper, matching vanilla chain rules).
            $changed = $this->pushToBelow($chunks, $chests, $furnaces, $containers, $brewing, $hopper, $x, $y, $z) || $changed;

            if ($changed) {
                $network?->syncContainer(ContainerStore::TYPE_HOPPER, $x, $y, $z, null, $worldId);
            }
        }
    }

    private function pullFromAbove(
        ?ChunkStore $chunks,
        ChestStore $chests,
        FurnaceStore $furnaces,
        ContainerStore $containers,
        BrewingStore $brewing,
        InventoryComponent $hopper,
        int $x,
        int $y,
        int $z,
    ): bool {
        if (!$hopper->canAddItem(new ItemStack(0, 0, 1)) && !$this->hasFreeSlot($hopper)) {
            return false; // hopper full
        }
        $source = ContainerLookup::inventoryAt($chunks, $chests, $furnaces, $containers, $brewing, $x, $y + 1, $z);
        if ($source === null) {
            return false;
        }
        $fromSlot = $this->firstOccupied($source);
        if ($fromSlot < 0) {
            return false;
        }
        $stack = $source->get($fromSlot);
        if ($stack === null || $stack->count <= 0) {
            return false;
        }
        $one = new ItemStack($stack->itemId, $stack->meta, 1, $stack->nbt);
        if (!$this->canAddOne($hopper, $one)) {
            return false;
        }
        $hopper->add($one);
        $source->remove($fromSlot, 1);
        return true;
    }

    private function pushToBelow(
        ?ChunkStore $chunks,
        ChestStore $chests,
        FurnaceStore $furnaces,
        ContainerStore $containers,
        BrewingStore $brewing,
        InventoryComponent $hopper,
        int $x,
        int $y,
        int $z,
    ): bool {
        // Vanilla: a hopper never pushes into a hopper directly below it.
        if ($chunks !== null && $chunks->getBlock($x, $y - 1, $z) === 154) {
            return false;
        }
        $target = ContainerLookup::inventoryAt($chunks, $chests, $furnaces, $containers, $brewing, $x, $y - 1, $z);
        if ($target === null) {
            return false;
        }
        $fromSlot = $this->firstOccupied($hopper);
        if ($fromSlot < 0) {
            return false;
        }
        $stack = $hopper->get($fromSlot);
        if ($stack === null || $stack->count <= 0) {
            return false;
        }
        $one = new ItemStack($stack->itemId, $stack->meta, 1, $stack->nbt);
        if (!$this->canAddOne($target, $one)) {
            return false;
        }
        $target->add($one);
        $hopper->remove($fromSlot, 1);
        return true;
    }

    private function firstOccupied(InventoryComponent $inv): int {
        foreach ($inv->getContents() as $slot => $item) {
            if ($item->count > 0) {
                return $slot;
            }
        }
        return -1;
    }

    private function hasFreeSlot(InventoryComponent $inv): bool {
        return count($inv->getContents()) < $inv->size;
    }

    private function canAddOne(InventoryComponent $inv, ItemStack $one): bool {
        if ($this->hasFreeSlot($inv)) {
            return true;
        }
        foreach ($inv->getContents() as $item) {
            if ($item->canStackWith($one) && $item->count < $item->getMaxStackSize()) {
                return true;
            }
        }
        return false;
    }
}

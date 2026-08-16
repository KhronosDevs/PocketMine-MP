<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

use pocketmine\core\component\InventoryComponent;

/**
 * Resolves the container inventory at a block position across every block
 * store type (chest, furnace, dispenser, hopper, brewing stand). Used by the
 * HopperSystem (pull above / push below) and any other cross-container logic.
 *
 * Position lookup is authoritative on the block id: a chest block id maps to
 * the ChestStore, a furnace id to the FurnaceStore, and so on. Returns null
 * when the position holds no known container.
 */
final class ContainerLookup {
    private function __construct() {}

    /**
     * The inventory of the container block at a position, or null.
     */
    public static function inventoryAt(
        ?ChunkStore $chunks,
        ChestStore $chests,
        FurnaceStore $furnaces,
        ContainerStore $containers,
        BrewingStore $brewing,
        int $x,
        int $y,
        int $z,
    ): ?InventoryComponent {
        if ($chunks === null) {
            return null;
        }
        return match ($chunks->getBlock($x, $y, $z)) {
            54 => $chests->get($x, $y, $z),
            61, 62 => $furnaces->get($x, $y, $z)['inventory'],
            23 => $containers->get(ContainerStore::TYPE_DISPENSER, $x, $y, $z),
            154 => $containers->get(ContainerStore::TYPE_HOPPER, $x, $y, $z),
            117 => $brewing->get($x, $y, $z)['inventory'],
            default => null,
        };
    }
}

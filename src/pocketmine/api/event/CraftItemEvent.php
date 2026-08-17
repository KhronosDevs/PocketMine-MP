<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Player;
use pocketmine\api\inventory\ItemStack;

/**
 * Fires when a player crafts an item (2x2 or 3x3 grid). Cancelling leaves
 * the ingredients in the inventory.
 */
class CraftItemEvent extends CancellableEvent {
    /** @param list<array{0: int, 1: int}> $grid ingredient id/meta per cell */
    public function __construct(
        public readonly Player $player,
        public readonly ItemStack $result,
        public readonly array $grid,
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }

    public function getResult(): ItemStack {
        return $this->result;
    }

    public function getGrid(): array {
        return $this->grid;
    }
}

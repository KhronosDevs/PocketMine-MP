<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Player;
use pocketmine\api\inventory\ItemStack;

/**
 * Fires when a player eats a food item. Cancelling reverts the consumption
 * (the item stays, hunger is not applied).
 */
class PlayerItemConsumeEvent extends CancellableEvent {
    public function __construct(
        public readonly Player $player,
        public readonly ItemStack $item,
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }

    public function getItem(): ItemStack {
        return $this->item;
    }
}

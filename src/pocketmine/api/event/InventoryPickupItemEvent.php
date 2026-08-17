<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Entity;
use pocketmine\api\entity\Player;
use pocketmine\api\inventory\ItemStack;

/**
 * Fires when a player picks up a dropped item entity. Cancelling leaves the
 * item on the ground.
 */
class InventoryPickupItemEvent extends CancellableEvent {
    public function __construct(
        public readonly Player $player,
        public readonly Entity $itemEntity,
        public readonly ItemStack $item,
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }

    public function getItemEntity(): Entity {
        return $this->itemEntity;
    }

    public function getItem(): ItemStack {
        return $this->item;
    }
}

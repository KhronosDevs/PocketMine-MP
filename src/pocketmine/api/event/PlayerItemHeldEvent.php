<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Player;
use pocketmine\api\inventory\ItemStack;

/**
 * Fires when a player selects a different hotbar slot (MobEquipmentPacket).
 * Cancelling rejects the slot change - the held slot stays where it was.
 */
class PlayerItemHeldEvent extends CancellableEvent {
    public function __construct(
        public readonly Player $player,
        public readonly ?ItemStack $item,
        public readonly int $inventorySlot,
        public readonly int $slot,
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }

    public function getItem(): ?ItemStack {
        return $this->item;
    }

    public function getInventorySlot(): int {
        return $this->inventorySlot;
    }

    public function getSlot(): int {
        return $this->slot;
    }
}

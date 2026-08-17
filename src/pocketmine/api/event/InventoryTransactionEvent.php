<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Player;

/**
 * Fires when a player reports an inventory slot change (ContainerSetSlot
 * for the player inventory, armor, or an open container window). Cancelling
 * rejects the transaction - the authoritative inventory state is kept.
 */
class InventoryTransactionEvent extends CancellableEvent {
    public function __construct(
        public readonly Player $player,
        public readonly int $windowId,
        public readonly int $slot,
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }

    public function getWindowId(): int {
        return $this->windowId;
    }

    public function getSlot(): int {
        return $this->slot;
    }
}

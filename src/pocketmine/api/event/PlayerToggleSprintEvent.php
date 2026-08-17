<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Player;

/**
 * Fires when a player starts or stops sprinting. Cancelling reverts the
 * toggle.
 */
class PlayerToggleSprintEvent extends CancellableEvent {
    public function __construct(
        public readonly Player $player,
        public readonly bool $sprinting,
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }

    public function isSprinting(): bool {
        return $this->sprinting;
    }
}

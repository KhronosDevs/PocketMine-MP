<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Player;

/**
 * Fires when a player starts or stops sneaking. Cancelling reverts the
 * toggle (the metadata flag stays as it was).
 */
class PlayerToggleSneakEvent extends CancellableEvent {
    public function __construct(
        public readonly Player $player,
        public readonly bool $sneaking,
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }

    public function isSneaking(): bool {
        return $this->sneaking;
    }
}

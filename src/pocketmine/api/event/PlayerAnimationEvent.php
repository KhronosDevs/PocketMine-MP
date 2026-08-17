<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Player;

/**
 * Fires when a player sends an animation packet (AnimatePacket) - the
 * default ARM_SWING is the arm swing on left-click / punch. Cancelling
 * suppresses the animation from being broadcast to other players.
 */
class PlayerAnimationEvent extends CancellableEvent {
    public const ARM_SWING = 1;
    public const WAKE_UP = 3;

    public function __construct(
        public readonly Player $player,
        public readonly int $animationType = self::ARM_SWING,
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }

    public function getAnimationType(): int {
        return $this->animationType;
    }
}

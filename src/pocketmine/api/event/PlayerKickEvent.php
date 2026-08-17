<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Player;

/**
 * Fires when a player is kicked (by command, ban, or anti-cheat). The kick
 * message and the quit message broadcast to others can both be changed;
 * cancelling stops the kick entirely.
 */
class PlayerKickEvent extends CancellableEvent {
    public function __construct(
        public readonly Player $player,
        public string $reason,
        public string $quitMessage = '',
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }

    public function getReason(): string {
        return $this->reason;
    }

    public function setReason(string $reason): void {
        $this->reason = $reason;
    }

    public function getQuitMessage(): string {
        return $this->quitMessage;
    }

    public function setQuitMessage(string $message): void {
        $this->quitMessage = $message;
    }
}

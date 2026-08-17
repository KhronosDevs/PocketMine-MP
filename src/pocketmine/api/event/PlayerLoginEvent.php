<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Player;

/**
 * Fires when a player's login passes protocol/ban checks but before the
 * join completes (entity + session exist). Cancelling blocks the join with
 * the kick message.
 */
class PlayerLoginEvent extends CancellableEvent {
    public function __construct(
        public readonly Player $player,
        public string $kickMessage = '',
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }

    public function getKickMessage(): string {
        return $this->kickMessage;
    }

    public function setKickMessage(string $message): void {
        $this->kickMessage = $message;
    }
}

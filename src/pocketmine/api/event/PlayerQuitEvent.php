<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Player;

/**
 * Fires when a player leaves the server, before the player data is saved
 * and the entity despawned. Informational - plugins can read the quit
 * reason and clean up their per-player state here.
 */
class PlayerQuitEvent extends Event {
    public function __construct(
        public readonly Player $player,
        public string $quitMessage = "",
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }

    public function getQuitMessage(): string {
        return $this->quitMessage;
    }

    public function setQuitMessage(string $quitMessage): void {
        $this->quitMessage = $quitMessage;
    }
}

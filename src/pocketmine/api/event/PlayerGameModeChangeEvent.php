<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Player;
use pocketmine\core\enum\GameMode;

/**
 * Fires when a player's gamemode changes (/gamemode, force-gamemode on
 * join). Cancelling keeps the old gamemode.
 */
class PlayerGameModeChangeEvent extends CancellableEvent {
    public function __construct(
        public readonly Player $player,
        public readonly GameMode $newGameMode,
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }

    public function getNewGameMode(): GameMode {
        return $this->newGameMode;
    }

    /** @return int legacy numeric gamemode (0 survival, 1 creative, ...) */
    public function getNewGamemodeValue(): int {
        return $this->newGameMode->value;
    }
}

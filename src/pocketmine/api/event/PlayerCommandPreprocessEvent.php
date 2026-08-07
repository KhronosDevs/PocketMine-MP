<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Player;
use pocketmine\api\entity\Entity;
use pocketmine\api\block\Block;

class PlayerCommandPreprocessEvent extends CancellableEvent {
    public function __construct(
        public readonly Player $player,
        public string $command,
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }

    public function getCommand(): string {
        return $this->command;
    }

    public function setCommand(string $command): void {
        $this->command = $command;
    }
}

<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Player;
use pocketmine\api\entity\Entity;
use pocketmine\api\block\Block;

class PlayerDeathEvent extends Event {
    public function __construct(
        public readonly Player $player,
        public readonly ?Entity $killer = null,
        public string $deathMessage = "",
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }

    public function getKiller(): ?Entity {
        return $this->killer;
    }

    public function getDeathMessage(): string {
        return $this->deathMessage;
    }

    public function setDeathMessage(string $message): void {
        $this->deathMessage = $message;
    }
}

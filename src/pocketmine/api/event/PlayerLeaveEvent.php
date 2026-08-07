<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Player;
use pocketmine\api\entity\Entity;
use pocketmine\api\block\Block;

class PlayerLeaveEvent extends Event {
    public function __construct(
        public readonly Player $player,
        public string $reason = "",
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }

    public function getReason(): string {
        return $this->reason;
    }
}

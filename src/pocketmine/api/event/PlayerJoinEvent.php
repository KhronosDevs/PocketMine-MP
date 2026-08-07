<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Player;
use pocketmine\api\entity\Entity;
use pocketmine\api\block\Block;

class PlayerJoinEvent extends Event {
    public function __construct(
        public readonly Player $player,
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }
}

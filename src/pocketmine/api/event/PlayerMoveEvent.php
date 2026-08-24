<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Player;
use pocketmine\api\entity\Entity;
use pocketmine\api\block\Block;

class PlayerMoveEvent extends CancellableEvent {
    public function __construct(
        public readonly Player $player,
        public readonly array $from,
        public readonly array $to,
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }

    public function getFrom(): array {
        return $this->from;
    }

    public function getTo(): array {
        return $this->to;
    }
}

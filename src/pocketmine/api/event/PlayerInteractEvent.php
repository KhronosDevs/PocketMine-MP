<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Player;
use pocketmine\api\entity\Entity;
use pocketmine\api\block\Block;

class PlayerInteractEvent extends CancellableEvent {
    public const LEFT_CLICK_AIR = 0;
    public const LEFT_CLICK_BLOCK = 1;
    public const RIGHT_CLICK_AIR = 2;
    public const RIGHT_CLICK_BLOCK = 3;

    public function __construct(
        public readonly Player $player,
        public readonly int $action,
        public readonly ?Entity $target = null,
        public readonly int $face = 0,
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }

    public function getAction(): int {
        return $this->action;
    }

    public function getTarget(): ?Entity {
        return $this->target;
    }

    public function getFace(): int {
        return $this->face;
    }
}

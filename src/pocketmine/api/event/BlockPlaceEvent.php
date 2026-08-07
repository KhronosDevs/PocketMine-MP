<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Player;
use pocketmine\api\entity\Entity;
use pocketmine\api\block\Block;

class BlockPlaceEvent extends CancellableEvent {
    public function __construct(
        public readonly Player $player,
        public readonly Block $block,
        public readonly int $face = 0,
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }

    public function getBlock(): Block {
        return $this->block;
    }

    public function getFace(): int {
        return $this->face;
    }
}

<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Player;
use pocketmine\api\entity\Entity;
use pocketmine\api\block\Block;

class BlockBreakEvent extends CancellableEvent {
    public function __construct(
        public readonly Player $player,
        public readonly Block $block,
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }

    public function getBlock(): Block {
        return $this->block;
    }
}

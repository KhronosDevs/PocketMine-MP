<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\block\Block;

/**
 * Fires when a block update is triggered (placement, break, neighbor
 * change). Informational - plugins can react to block state changes.
 */
class BlockUpdateEvent extends Event {
    public function __construct(
        public readonly Block $block,
    ) {}

    public function getBlock(): Block {
        return $this->block;
    }
}

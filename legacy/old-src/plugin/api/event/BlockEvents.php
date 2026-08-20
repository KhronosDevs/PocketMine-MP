<?php

declare(strict_types=1);

namespace pocketmine\plugin\api\event;

use pocketmine\domain\ecs\EntityRef;
use pocketmine\domain\component\PositionComponent;

final class BlockBreakEvent extends CancellableEvent {
    public function __construct(
        public readonly EntityRef $player,
        public readonly PositionComponent $position,
        public readonly int $blockId,
        public readonly int $meta = 0,
    ) {}

    public function getPlayer(): EntityRef {
        return $this->player;
    }

    public function getPosition(): PositionComponent {
        return $this->position;
    }

    public function getBlockId(): int {
        return $this->blockId;
    }

    public function getMeta(): int {
        return $this->meta;
    }
}

final class BlockPlaceEvent extends CancellableEvent {
    public function __construct(
        public readonly EntityRef $player,
        public readonly PositionComponent $position,
        public readonly int $blockId,
        public readonly int $meta = 0,
        public readonly int $face = 0,
    ) {}

    public function getPlayer(): EntityRef {
        return $this->player;
    }

    public function getPosition(): PositionComponent {
        return $this->position;
    }

    public function getBlockId(): int {
        return $this->blockId;
    }

    public function getMeta(): int {
        return $this->meta;
    }

    public function getFace(): int {
        return $this->face;
    }
}

final class BlockUpdateEvent extends Event {
    public function __construct(
        public readonly PositionComponent $position,
        public readonly int $blockId,
        public readonly int $meta = 0,
    ) {}

    public function getPosition(): PositionComponent {
        return $this->position;
    }

    public function getBlockId(): int {
        return $this->blockId;
    }

    public function getMeta(): int {
        return $this->meta;
    }
}

final class BlockRedstoneEvent extends Event {
    public function __construct(
        public readonly PositionComponent $position,
        public readonly int $oldPower,
        public readonly int $newPower,
    ) {}

    public function getPosition(): PositionComponent {
        return $this->position;
    }

    public function getOldPower(): int {
        return $this->oldPower;
    }

    public function getNewPower(): int {
        return $this->newPower;
    }
}
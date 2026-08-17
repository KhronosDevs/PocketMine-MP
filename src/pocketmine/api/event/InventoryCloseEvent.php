<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Player;

/**
 * Fires when a player closes a container window.
 */
class InventoryCloseEvent extends Event {
    /** @param array{x: int, y: int, z: int}|null $position */
    public function __construct(
        public readonly Player $player,
        public readonly string $containerType,
        public readonly ?array $position = null,
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }

    public function getContainerType(): string {
        return $this->containerType;
    }

    public function getPosition(): ?array {
        return $this->position;
    }
}

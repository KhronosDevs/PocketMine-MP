<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Entity;

/**
 * Fires when an item entity spawns into the world (a dropped item). The
 * wrapped entity is an ItemEntity carrying the item stack. Informational.
 */
class ItemSpawnEvent extends Event {
    public function __construct(
        public readonly Entity $entity,
    ) {}

    public function getEntity(): Entity {
        return $this->entity;
    }
}

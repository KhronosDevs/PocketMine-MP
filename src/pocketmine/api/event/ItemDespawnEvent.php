<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Entity;

/**
 * Fires before an item entity is removed from the world (despawned).
 * Cancelling keeps the item in the world.
 */
class ItemDespawnEvent extends CancellableEvent {
    public function __construct(
        public readonly Entity $entity,
    ) {}

    public function getEntity(): Entity {
        return $this->entity;
    }
}

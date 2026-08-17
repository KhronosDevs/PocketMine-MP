<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Entity;

/**
 * Fires when an entity is removed from the world (despawn, death cleanup).
 */
class EntityDespawnEvent extends Event {
    public function __construct(
        public readonly Entity $entity,
    ) {}

    public function getEntity(): Entity {
        return $this->entity;
    }
}

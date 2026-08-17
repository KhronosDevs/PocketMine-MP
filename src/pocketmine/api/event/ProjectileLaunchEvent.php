<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Entity;

/**
 * Fires when a projectile (arrow, snowball, egg, potion) is launched.
 * Cancelling prevents the projectile from flying - it is despawned and the
 * shot does not consume ammo.
 */
class ProjectileLaunchEvent extends CancellableEvent {
    public function __construct(
        public readonly Entity $entity,
        public readonly ?Entity $shooter = null,
    ) {}

    public function getEntity(): Entity {
        return $this->entity;
    }

    public function getShooter(): ?Entity {
        return $this->shooter;
    }
}

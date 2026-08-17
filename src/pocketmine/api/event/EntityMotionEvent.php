<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Entity;

/**
 * Fires before motion (knockback, explosion impulse) is applied to an
 * entity. Cancelling keeps the entity's current velocity unchanged.
 */
class EntityMotionEvent extends CancellableEvent {
    public function __construct(
        public readonly Entity $entity,
        public readonly float $motionX,
        public readonly float $motionY,
        public readonly float $motionZ,
    ) {}

    public function getEntity(): Entity {
        return $this->entity;
    }

    public function getMotionX(): float {
        return $this->motionX;
    }

    public function getMotionY(): float {
        return $this->motionY;
    }

    public function getMotionZ(): float {
        return $this->motionZ;
    }
}

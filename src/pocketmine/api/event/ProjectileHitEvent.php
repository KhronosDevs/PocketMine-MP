<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Entity;

/**
 * Fires when a projectile hits a block or a living entity. The hit position
 * is the impact point in world coordinates; hitEntity is null for block hits.
 * Informational.
 */
class ProjectileHitEvent extends Event {
    public function __construct(
        public readonly Entity $entity,
        public readonly ?Entity $hitEntity,
        public readonly float $hitX,
        public readonly float $hitY,
        public readonly float $hitZ,
    ) {}

    public function getEntity(): Entity {
        return $this->entity;
    }

    public function getHitEntity(): ?Entity {
        return $this->hitEntity;
    }

    public function getHitX(): float {
        return $this->hitX;
    }

    public function getHitY(): float {
        return $this->hitY;
    }

    public function getHitZ(): float {
        return $this->hitZ;
    }
}

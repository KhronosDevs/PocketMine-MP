<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Entity;
use pocketmine\api\inventory\ItemStack;

/**
 * Fires when an entity releases a charged bow. Cancelling stops the arrow
 * from spawning (and in survival, keeps the arrow in the inventory).
 */
class EntityShootBowEvent extends CancellableEvent {
    public function __construct(
        public readonly Entity $shooter,
        public readonly ItemStack $bow,
        public float $force,
    ) {}

    public function getShooter(): Entity {
        return $this->shooter;
    }

    public function getBow(): ItemStack {
        return $this->bow;
    }

    public function getForce(): float {
        return $this->force;
    }

    public function setForce(float $force): void {
        $this->force = max(0.0, $force);
    }
}

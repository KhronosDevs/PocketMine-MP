<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Entity;

/**
 * Fires when an entity regains health (natural regen, food, effects).
 * Cancelling prevents the heal.
 */
class EntityRegainHealthEvent extends CancellableEvent {
    public const REASON_REGEN = 0;
    public const REASON_FOOD = 1;
    public const REASON_MAGIC = 2;
    public const REASON_CUSTOM = 3;

    public function __construct(
        public readonly Entity $entity,
        public float $amount,
        public readonly int $reason = self::REASON_CUSTOM,
    ) {}

    public function getEntity(): Entity {
        return $this->entity;
    }

    public function getAmount(): float {
        return $this->amount;
    }

    public function setAmount(float $amount): void {
        $this->amount = max(0.0, $amount);
    }

    public function getReason(): int {
        return $this->reason;
    }
}

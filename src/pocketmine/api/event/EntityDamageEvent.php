<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Player;
use pocketmine\api\entity\Entity;
use pocketmine\api\block\Block;

class EntityDamageEvent extends CancellableEvent {
    public const CAUSE_UNKNOWN = 0;
    public const CAUSE_CONTACT = 1;
    public const CAUSE_ENTITY_ATTACK = 2;
    public const CAUSE_PROJECTILE = 3;
    public const CAUSE_SUFFOCATION = 4;
    public const CAUSE_FALL = 5;
    public const CAUSE_FIRE = 6;
    public const CAUSE_FIRE_TICK = 7;
    public const CAUSE_LAVA = 8;
    public const CAUSE_DROWNING = 9;
    public const CAUSE_VOID = 10;
    public const CAUSE_SUICIDE = 11;
    public const CAUSE_STARVATION = 12;
    public const CAUSE_POISON = 13;
    public const CAUSE_MAGIC = 14;
    public const CAUSE_WITHER = 15;
    public const CAUSE_FALLING_BLOCK = 16;
    public const CAUSE_THORNS = 17;
    public const CAUSE_EXPLOSION = 18;
    public const CAUSE_DRAGON_BREATH = 19;
    public const CAUSE_CUSTOM = 20;

    public function __construct(
        public readonly Entity $entity,
        public readonly int $cause,
        public float $damage,
    ) {}

    public function getEntity(): Entity {
        return $this->entity;
    }

    public function getCause(): int {
        return $this->cause;
    }

    public function getDamage(): float {
        return $this->damage;
    }

    public function setDamage(float $damage): void {
        $this->damage = $damage;
    }

    public function getFinalDamage(): float {
        return $this->damage;
    }
}

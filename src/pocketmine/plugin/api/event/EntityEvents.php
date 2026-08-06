<?php

declare(strict_types=1);

namespace pocketmine\plugin\api\event;

use pocketmine\domain\ecs\EntityRef;

final class EntitySpawnEvent extends Event {
    public function __construct(
        public readonly EntityRef $entity,
    ) {}

    public function getEntity(): EntityRef {
        return $this->entity;
    }
}

final class EntityDespawnEvent extends Event {
    public function __construct(
        public readonly EntityRef $entity,
        public readonly string $reason = "",
    ) {}

    public function getEntity(): EntityRef {
        return $this->entity;
    }

    public function getReason(): string {
        return $this->reason;
    }
}

final class EntityDamageEvent extends CancellableEvent {
    public const int CAUSE_UNKNOWN = 0;
    public const int CAUSE_CONTACT = 1;
    public const int CAUSE_ENTITY_ATTACK = 2;
    public const int CAUSE_PROJECTILE = 3;
    public const int CAUSE_SUFFOCATION = 4;
    public const int CAUSE_FALL = 5;
    public const int CAUSE_FIRE = 6;
    public const int CAUSE_FIRE_TICK = 7;
    public const int CAUSE_LAVA = 8;
    public const int CAUSE_DROWNING = 9;
    public const int CAUSE_VOID = 10;
    public const int CAUSE_SUICIDE = 11;
    public const int CAUSE_STARVATION = 12;
    public const int CAUSE_POISON = 13;
    public const int CAUSE_MAGIC = 14;
    public const int CAUSE_WITHER = 15;
    public const int CAUSE_FALLING_BLOCK = 16;
    public const int CAUSE_THORNS = 17;
    public const int CAUSE_EXPLOSION = 18;
    public const int CAUSE_DRAGON_BREATH = 19;
    public const int CAUSE_CUSTOM = 20;

    public function __construct(
        public readonly EntityRef $entity,
        public readonly int $cause,
        public float $damage,
    ) {}

    public function getEntity(): EntityRef {
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

final class EntityDamageByEntityEvent extends EntityDamageEvent {
    public function __construct(
        public readonly EntityRef $entity,
        public readonly EntityRef $damager,
        public float $damage,
    ) {
        parent::__construct($entity, self::CAUSE_ENTITY_ATTACK, $damage);
    }

    public function getDamager(): EntityRef {
        return $this->damager;
    }
}

final class EntityDamageByBlockEvent extends EntityDamageEvent {
    public function __construct(
        public readonly EntityRef $entity,
        public readonly \pocketmine\domain\component\PositionComponent $blockPosition,
        public float $damage,
    ) {
        parent::__construct($entity, self::CAUSE_CONTACT, $damage);
    }

    public function getBlockPosition(): \pocketmine\domain\component\PositionComponent {
        return $this->blockPosition;
    }
}

final class EntityDeathEvent extends Event {
    public function __construct(
        public readonly EntityRef $entity,
        public readonly ?EntityRef $killer = null,
    ) {}

    public function getEntity(): EntityRef {
        return $this->entity;
    }

    public function getKiller(): ?EntityRef {
        return $this->killer;
    }
}

final class EntityTeleportEvent extends CancellableEvent {
    public function __construct(
        public readonly EntityRef $entity,
        public readonly \pocketmine\domain\component\PositionComponent $from,
        public readonly \pocketmine\domain\component\PositionComponent $to,
    ) {}

    public function getEntity(): EntityRef {
        return $this->entity;
    }

    public function getFrom(): \pocketmine\domain\component\PositionComponent {
        return $this->from;
    }

    public function getTo(): \pocketmine\domain\component\PositionComponent {
        return $this->to;
    }
}
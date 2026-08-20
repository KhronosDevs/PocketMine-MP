<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Player;
use pocketmine\api\entity\Entity;
use pocketmine\api\level\Level;
use pocketmine\api\block\Block;

class PlayerJoinEvent extends Event {
    public function __construct(
        public readonly Player $player,
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }
}

class PlayerLeaveEvent extends Event {
    public function __construct(
        public readonly Player $player,
        public string $reason = "",
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }

    public function getReason(): string {
        return $this->reason;
    }
}

class PlayerRespawnEvent extends Event {
    public function __construct(
        public readonly Player $player,
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }
}

class PlayerMoveEvent extends Event {
    public function __construct(
        public readonly Player $player,
        public readonly array $from,
        public readonly array $to,
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }

    public function getFrom(): array {
        return $this->from;
    }

    public function getTo(): array {
        return $this->to;
    }
}

class PlayerChatEvent extends CancellableEvent {
    public function __construct(
        public readonly Player $player,
        public string $message,
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }

    public function getMessage(): string {
        return $this->message;
    }

    public function setMessage(string $message): void {
        $this->message = $message;
    }
}

class PlayerInteractEvent extends CancellableEvent {
    public const int LEFT_CLICK_AIR = 0;
    public const int LEFT_CLICK_BLOCK = 1;
    public const int RIGHT_CLICK_AIR = 2;
    public const int RIGHT_CLICK_BLOCK = 3;

    public function __construct(
        public readonly Player $player,
        public readonly int $action,
        public readonly ?Entity $target = null,
        public readonly int $face = 0,
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }

    public function getAction(): int {
        return $this->action;
    }

    public function getTarget(): ?Entity {
        return $this->target;
    }

    public function getFace(): int {
        return $this->face;
    }
}

class PlayerCommandPreprocessEvent extends CancellableEvent {
    public function __construct(
        public readonly Player $player,
        public string $command,
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }

    public function getCommand(): string {
        return $this->command;
    }

    public function setCommand(string $command): void {
        $this->command = $command;
    }
}

class PlayerDeathEvent extends Event {
    public function __construct(
        public readonly Player $player,
        public readonly ?Entity $killer = null,
        public readonly string $deathMessage = "",
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }

    public function getKiller(): ?Entity {
        return $this->killer;
    }

    public function getDeathMessage(): string {
        return $this->deathMessage;
    }

    public function setDeathMessage(string $message): void {
        $this->deathMessage = $message;
    }
}

class BlockBreakEvent extends CancellableEvent {
    public function __construct(
        public readonly Player $player,
        public readonly Block $block,
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }

    public function getBlock(): Block {
        return $this->block;
    }
}

class BlockPlaceEvent extends CancellableEvent {
    public function __construct(
        public readonly Player $player,
        public readonly Block $block,
        public readonly int $face = 0,
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }

    public function getBlock(): Block {
        return $this->block;
    }

    public function getFace(): int {
        return $this->face;
    }
}

class EntityDamageEvent extends CancellableEvent {
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

class EntityDeathEvent extends Event {
    public function __construct(
        public readonly Entity $entity,
        public readonly ?Entity $killer = null,
    ) {}

    public function getEntity(): Entity {
        return $this->entity;
    }

    public function getKiller(): ?Entity {
        return $this->killer;
    }
}

class EntitySpawnEvent extends Event {
    public function __construct(
        public readonly Entity $entity,
    ) {}

    public function getEntity(): Entity {
        return $this->entity;
    }
}
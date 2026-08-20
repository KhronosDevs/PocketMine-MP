<?php

declare(strict_types=1);

namespace pocketmine\plugin\api\event;

use pocketmine\domain\ecs\EntityRef;
use pocketmine\domain\component\PositionComponent;

final class PlayerJoinEvent extends Event {
    public function __construct(
        public readonly EntityRef $player,
    ) {}

    public function getPlayer(): EntityRef {
        return $this->player;
    }
}

final class PlayerLeaveEvent extends Event {
    public function __construct(
        public readonly EntityRef $player,
        public string $reason = "",
    ) {}

    public function getPlayer(): EntityRef {
        return $this->player;
    }

    public function getReason(): string {
        return $this->reason;
    }
}

final class PlayerRespawnEvent extends Event {
    public function __construct(
        public readonly EntityRef $player,
    ) {}

    public function getPlayer(): EntityRef {
        return $this->player;
    }
}

final class PlayerMoveEvent extends Event {
    public function __construct(
        public readonly EntityRef $player,
        public readonly PositionComponent $from,
        public readonly PositionComponent $to,
    ) {}

    public function getPlayer(): EntityRef {
        return $this->player;
    }

    public function getFrom(): PositionComponent {
        return $this->from;
    }

    public function getTo(): PositionComponent {
        return $this->to;
    }
}

final class PlayerChatEvent extends CancellableEvent {
    public function __construct(
        public readonly EntityRef $player,
        public string $message,
    ) {}

    public function getPlayer(): EntityRef {
        return $this->player;
    }

    public function getMessage(): string {
        return $this->message;
    }

    public function setMessage(string $message): void {
        $this->message = $message;
    }
}

final class PlayerInteractEvent extends CancellableEvent {
    public const int LEFT_CLICK_AIR = 0;
    public const int LEFT_CLICK_BLOCK = 1;
    public const int RIGHT_CLICK_AIR = 2;
    public const int RIGHT_CLICK_BLOCK = 3;

    public function __construct(
        public readonly EntityRef $player,
        public readonly int $action,
        public readonly ?EntityRef $target = null,
        public readonly int $face = 0,
    ) {}

    public function getPlayer(): EntityRef {
        return $this->player;
    }

    public function getAction(): int {
        return $this->action;
    }

    public function getTarget(): ?EntityRef {
        return $this->target;
    }

    public function getFace(): int {
        return $this->face;
    }
}

final class PlayerCommandPreprocessEvent extends CancellableEvent {
    public function __construct(
        public readonly EntityRef $player,
        public string $command,
    ) {}

    public function getPlayer(): EntityRef {
        return $this->player;
    }

    public function getCommand(): string {
        return $this->command;
    }

    public function setCommand(string $command): void {
        $this->command = $command;
    }
}

final class PlayerDeathEvent extends Event {
    public function __construct(
        public readonly EntityRef $player,
        public readonly ?EntityRef $killer = null,
        public readonly string $deathMessage = "",
    ) {}

    public function getPlayer(): EntityRef {
        return $this->player;
    }

    public function getKiller(): ?EntityRef {
        return $this->killer;
    }

    public function getDeathMessage(): string {
        return $this->deathMessage;
    }

    public function setDeathMessage(string $message): void {
        $this->deathMessage = $message;
    }
}
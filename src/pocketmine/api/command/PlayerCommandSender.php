<?php

declare(strict_types=1);

namespace pocketmine\api\command;

use pocketmine\api\entity\Player;
use pocketmine\api\entity\EntityRef;

class PlayerCommandSender implements CommandSender {
    public function __construct(
        private Player $player,
    ) {}

    public function sendMessage(string $message): void {
        $this->player->sendMessage($message);
    }

    public function hasPermission(string $permission): bool {
        return $this->player->hasPermission($permission);
    }

    public function getName(): string {
        return $this->player->getName();
    }

    public function isPlayer(): bool {
        return true;
    }

    public function getPlayer(): ?Player {
        return $this->player;
    }
}

<?php

declare(strict_types=1);

namespace pocketmine\api\command;

use pocketmine\api\entity\Player;
use pocketmine\api\entity\EntityRef;

class ConsoleCommandSender implements CommandSender {
    public function sendMessage(string $message): void {
        echo "[CONSOLE] $message\n";
    }

    public function hasPermission(string $permission): bool {
        return true;
    }

    public function getName(): string {
        return "CONSOLE";
    }

    public function isPlayer(): bool {
        return false;
    }

    public function getPlayer(): ?Player {
        return null;
    }
}

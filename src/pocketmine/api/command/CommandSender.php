<?php

declare(strict_types=1);

namespace pocketmine\api\command;

use pocketmine\api\entity\Player;
use pocketmine\api\entity\EntityRef;

interface CommandSender {
    public function sendMessage(string $message): void;
    public function hasPermission(string $permission): bool;
    public function getName(): string;
    public function isPlayer(): bool;
    public function getPlayer(): ?Player;
}

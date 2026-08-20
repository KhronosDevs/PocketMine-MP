<?php

declare(strict_types=1);

namespace pocketmine\port\driving;

interface CommandSender {
    public function sendMessage(string $message): void;

    public function hasPermission(string $permission): bool;

    public function getName(): string;
}
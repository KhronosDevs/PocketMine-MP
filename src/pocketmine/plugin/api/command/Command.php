<?php

declare(strict_types=1);

namespace pocketmine\plugin\api\command;

use pocketmine\plugin\api\event\EventBus;
use pocketmine\domain\ecs\EntityRef;

interface CommandExecutor {
    public function execute(CommandSender $sender, array $args): bool;
}

interface CommandSender {
    public function sendMessage(string $message): void;
    public function hasPermission(string $permission): bool;
    public function getName(): string;
    public function isPlayer(): bool;
    public function getPlayer(): ?EntityRef;
}

abstract class Command implements CommandExecutor {
    public function __construct(
        public readonly string $name,
        public readonly string $description = "",
        public readonly string $usage = "",
        public readonly array $aliases = [],
        public readonly ?string $permission = null,
    ) {}

    public function getName(): string {
        return $this->name;
    }

    public function getDescription(): string {
        return $this->description;
    }

    public function getUsage(): string {
        return $this->usage;
    }

    public function getAliases(): array {
        return $this->aliases;
    }

    public function getPermission(): ?string {
        return $this->permission;
    }

    abstract public function execute(CommandSender $sender, array $args): bool;

    protected function sendUsage(CommandSender $sender): void {
        $sender->sendMessage("Usage: {$this->usage}");
    }
}

final class CommandContext {
    public function __construct(
        public readonly CommandSender $sender,
        public readonly array $args,
        public readonly Command $command,
    ) {}
}
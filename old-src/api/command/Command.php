<?php

declare(strict_types=1);

namespace pocketmine\api\command;

use pocketmine\api\plugin\Plugin;

abstract class Command {
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

    public function testPermission(CommandSender $sender): bool {
        if ($this->permission === null) {
            return true;
        }
        return $sender->hasPermission($this->permission);
    }
}

class PluginCommand extends Command {
    public function __construct(
        string $name,
        Plugin $owner,
        public readonly string $description = "",
        public readonly string $usage = "",
        public readonly array $aliases = [],
        public readonly ?string $permission = null,
    ) {
        parent::__construct($name, $description, $usage, $aliases, $permission);
    }

    public function execute(CommandSender $sender, array $args): bool {
        // Would call plugin's onCommand
        return true;
    }
}
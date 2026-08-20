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

class CommandMap {
    private array $commands = [];

    public function register(Command $command): void {
        $this->commands[$command->getName()] = $command;
        foreach ($command->getAliases() as $alias) {
            $this->commands[$alias] = $command;
        }
    }

    public function unregister(string $name): void {
        $command = $this->commands[$name] ?? null;
        if ($command) {
            unset($this->commands[$command->getName()]);
            foreach ($command->getAliases() as $alias) {
                unset($this->commands[$alias]);
            }
        }
    }

    public function getCommand(string $name): ?Command {
        return $this->commands[$name] ?? null;
    }

    public function getCommands(): array {
        return array_values($this->commands);
    }

    public function execute(CommandSender $sender, string $commandLine): bool {
        $parts = explode(' ', trim($commandLine));
        if (empty($parts)) {
            return false;
        }

        $name = array_shift($parts);
        $command = $this->getCommand($name);
        
        if (!$command) {
            $sender->sendMessage("Unknown command: $name");
            return false;
        }

        if (!$command->testPermission($sender)) {
            $sender->sendMessage("You don't have permission to use this command.");
            return false;
        }

        return $command->execute($sender, $parts);
    }
}
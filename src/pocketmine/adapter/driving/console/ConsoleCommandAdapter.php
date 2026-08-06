<?php

declare(strict_types=1);

namespace pocketmine\adapter\driving\console;

use pocketmine\port\driving\Command;
use pocketmine\port\driving\CommandPort;
use pocketmine\port\driving\CommandSender;

final class ConsoleCommandAdapter implements CommandPort {
    private array $commands = [];

    public function register(Command $command): void {
        $this->commands[$command->getName()] = $command;
        foreach ($command->getAliases() as $alias) {
            $this->commands[$alias] = $command;
        }
    }

    public function unregister(string $name): void {
        unset($this->commands[$name]);
    }

    public function getCommand(string $name): ?Command {
        return $this->commands[$name] ?? null;
    }

    public function execute(string $commandLine, CommandSender $sender): bool {
        $parts = explode(' ', trim($commandLine));
        $name = array_shift($parts);
        $command = $this->getCommand($name);
        if (!$command) {
            $sender->sendMessage("Unknown command: $name");
            return false;
        }
        return $command->execute($sender, $name, $parts);
    }
}
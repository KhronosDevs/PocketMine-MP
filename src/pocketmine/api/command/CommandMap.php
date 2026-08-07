<?php

declare(strict_types=1);

namespace pocketmine\api\command;

use pocketmine\api\entity\Player;
use pocketmine\api\entity\EntityRef;

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

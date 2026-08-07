<?php

declare(strict_types=1);

namespace pocketmine\api\command;

use pocketmine\api\event\EventBus;

/**
 * Dispatches command lines to registered commands, applying permission checks
 * and emitting the command preprocess event for players.
 */
final class CommandExecutor {
    public function __construct(
        private readonly CommandMap $commandMap,
        private readonly EventBus $eventBus,
    ) {}

    public function register(Command $command): void {
        $this->commandMap->register($command);
    }

    public function unregister(string $name): void {
        $this->commandMap->unregister($name);
    }

    public function getCommand(string $name): ?Command {
        return $this->commandMap->getCommand($name);
    }

    public function execute(CommandSender $sender, string $commandLine): bool {
        $parts = explode(' ', trim($commandLine));
        if (empty($parts)) {
            return false;
        }

        $name = array_shift($parts);
        $command = $this->commandMap->getCommand($name);

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

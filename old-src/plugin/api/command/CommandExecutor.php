<?php

declare(strict_types=1);

namespace pocketmine\plugin\api\command;

use pocketmine\plugin\api\event\EventBus;
use pocketmine\port\driving\CommandPort;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionNamedType;

final class CommandExecutor {
    public function __construct(
        private readonly CommandPort $commandPort,
        private readonly EventBus $eventBus,
    ) {}

    public function register(Command $command): void {
        $this->commandPort->register($command);
        
        foreach ($command->getAliases() as $alias) {
            $this->commandPort->register(new AliasCommand($alias, $command));
        }
    }

    public function unregister(string $name): void {
        $this->commandPort->unregister($name);
    }

    public function execute(CommandSender $sender, string $commandLine): bool {
        $parts = explode(' ', trim($commandLine));
        if (empty($parts)) {
            return false;
        }

        $name = array_shift($parts);
        $command = $this->commandPort->getCommand($name);
        
        if (!$command) {
            $sender->sendMessage("Unknown command: $name");
            return false;
        }

        // Check permission
        if ($command->getPermission() !== null && !$sender->hasPermission($command->getPermission())) {
            $sender->sendMessage("You don't have permission to use this command.");
            return false;
        }

        // Emit command preprocess event
        if ($sender->isPlayer()) {
            $event = new \pocketmine\plugin\api\event\PlayerCommandPreprocessEvent(
                $sender->getPlayer()!,
                $commandLine
            );
            $this->eventBus->emit($event);
            
            if ($event->isCancelled()) {
                return false;
            }
            
            // Update command if modified
            $commandLine = $event->getCommand();
            $parts = explode(' ', trim($commandLine));
            array_shift($parts);
        }

        return $command->execute($sender, $parts);
    }
}

final class AliasCommand extends Command {
    public function __construct(
        string $alias,
        private readonly Command $original,
    ) {
        parent::__construct(
            $alias,
            $original->getDescription(),
            $original->getUsage(),
            [],
            $original->getPermission(),
        );
    }

    public function execute(CommandSender $sender, array $args): bool {
        return $this->original->execute($sender, $args);
    }
}

final class CommandMap implements CommandPort {
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
}
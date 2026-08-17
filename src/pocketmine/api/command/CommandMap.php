<?php

declare(strict_types=1);

namespace pocketmine\api\command;

use pocketmine\api\event\PlayerCommandPreprocessEvent;
use pocketmine\api\event\ServerCommandEvent;
use pocketmine\port\driving\CommandPort;
use pocketmine\port\driving\EventPort;

/**
 * The single server-wide command map. Owned by the Kernel (it implements
 * CommandPort), so the Server facade, the console and plugins all share one
 * registry. Player commands pass through a cancellable preprocess event.
 */
class CommandMap implements CommandPort {
    /** @var array<string, Command> */
    private array $commands = [];

    public function __construct(
        private readonly EventPort $eventPort,
    ) {}

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
        // Blocker 4 audit: ServerCommandEvent fires for every sender (console
        // and player) before dispatch; player commands additionally fire
        // PlayerCommandPreprocessEvent so plugins can rewrite the line.
        $serverEvent = new ServerCommandEvent($sender, $commandLine);
        $this->eventPort->emit($serverEvent);
        if ($serverEvent->isCancelled()) {
            return false;
        }
        $commandLine = $serverEvent->getCommand();

        // Player commands are cancellable before execution.
        if ($sender->isPlayer() && $sender->getPlayer() !== null) {
            $event = new PlayerCommandPreprocessEvent($sender->getPlayer(), $commandLine);
            $this->eventPort->emit($event);
            if ($event->isCancelled()) {
                return false;
            }
            $commandLine = $event->getCommand();
        }

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

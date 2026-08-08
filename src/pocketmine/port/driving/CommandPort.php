<?php

declare(strict_types=1);

namespace pocketmine\port\driving;

use pocketmine\api\command\Command;
use pocketmine\api\command\CommandSender;

/**
 * The command seam: one server-wide command map shared by the Server facade,
 * the console, and plugins. Implemented by the api CommandMap so a command
 * registered anywhere is dispatchable everywhere.
 */
interface CommandPort {
    public function register(Command $command): void;

    public function unregister(string $name): void;

    public function getCommand(string $name): ?Command;

    public function execute(CommandSender $sender, string $commandLine): bool;
}

<?php

declare(strict_types=1);

namespace pocketmine\api\command\builtin;

use pocketmine\api\command\CommandSender;

/**
 * /help — lists every registered command and its description.
 */
final class HelpCommand extends BuiltinCommand {
    public function __construct() {
        parent::__construct(
            'help',
            'List available commands',
            '/help',
            ['?'],
        );
    }

    public function execute(CommandSender $sender, array $args): bool {
        $kernel = $this->kernel();
        if ($kernel === null) {
            return false;
        }
        $commands = $kernel->getCommandPort()->getCommands();
        if ($commands === []) {
            $sender->sendMessage('No commands registered.');
            return true;
        }
        foreach ($commands as $command) {
            $sender->sendMessage($command->getName() . ': ' . $command->getDescription());
        }
        return true;
    }
}

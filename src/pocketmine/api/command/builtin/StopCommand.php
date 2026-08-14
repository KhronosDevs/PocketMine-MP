<?php

declare(strict_types=1);

namespace pocketmine\api\command\builtin;

use pocketmine\api\command\CommandSender;

/**
 * /stop — gracefully stops the server: the run() loop breaks, world data and
 * player lists are persisted, and worker threads are shut down. Only the
 * console (or an op with the permission) can stop the server.
 */
final class StopCommand extends BuiltinCommand {
    public function __construct() {
        parent::__construct(
            'stop',
            'Gracefully stop the server (saves the world first)',
            '/stop',
            [],
            'khronos.command.stop',
        );
    }

    public function execute(CommandSender $sender, array $args): bool {
        $kernel = $this->kernel();
        if ($kernel === null) {
            return false;
        }
        $sender->sendMessage('Stopping server...');
        $kernel->requestShutdown();
        return true;
    }
}

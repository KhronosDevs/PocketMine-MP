<?php

declare(strict_types=1);

namespace pocketmine\api\command\builtin;

use pocketmine\api\command\CommandSender;

/**
 * /save-all — flush every resident chunk + world meta to disk immediately
 * (autosave already runs every 6000 ticks, this forces it on demand).
 */
final class SaveAllCommand extends BuiltinCommand {
    public function __construct() {
        parent::__construct(
            'save-all',
            'Save the world and player data to disk now',
            '/save-all',
            ['save'],
            'khronos.command.save-all',
        );
    }

    public function execute(CommandSender $sender, array $args): bool {
        $kernel = $this->kernel();
        if ($kernel === null) {
            return false;
        }
        $kernel->saveAllWorlds();
        $sender->sendMessage('World saved.');
        return true;
    }
}

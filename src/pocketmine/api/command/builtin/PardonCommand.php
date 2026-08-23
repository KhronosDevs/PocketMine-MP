<?php

declare(strict_types=1);

namespace pocketmine\api\command\builtin;

use pocketmine\api\command\CommandSender;

/**
 * /pardon <player> — lift a name ban.
 */
final class PardonCommand extends BuiltinCommand {
    public function __construct() {
        parent::__construct(
            'pardon',
            'Unban a player',
            '/pardon <player>',
            ['unban'],
            'khronos.command.pardon',
            category: 'admin',
        );
    }

    public function execute(CommandSender $sender, array $args): bool {
        $name = array_shift($args);
        if ($name === null || $name === '') {
            $sender->sendMessage('Usage: /pardon <player>');
            return false;
        }
        $lists = $this->playerLists();
        if ($lists === null) {
            return false;
        }
        $lists->pardon($name);
        $sender->sendMessage("Pardoned $name");
        return true;
    }
}

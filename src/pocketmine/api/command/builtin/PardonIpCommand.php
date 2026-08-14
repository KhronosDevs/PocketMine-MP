<?php

declare(strict_types=1);

namespace pocketmine\api\command\builtin;

use pocketmine\api\command\CommandSender;

/**
 * /pardon-ip <ip> — lift an IP ban.
 */
final class PardonIpCommand extends BuiltinCommand {
    public function __construct() {
        parent::__construct(
            'pardon-ip',
            'Unban an IP address',
            '/pardon-ip <ip>',
            [],
            'khronos.command.pardon-ip',
        );
    }

    public function execute(CommandSender $sender, array $args): bool {
        $ip = array_shift($args);
        if ($ip === null || $ip === '') {
            $sender->sendMessage('Usage: /pardon-ip <ip>');
            return false;
        }
        $lists = $this->playerLists();
        if ($lists === null) {
            return false;
        }
        $lists->pardonIp($ip);
        $sender->sendMessage("Pardoned IP $ip");
        return true;
    }
}

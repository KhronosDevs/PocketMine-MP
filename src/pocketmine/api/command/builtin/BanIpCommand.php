<?php

declare(strict_types=1);

namespace pocketmine\api\command\builtin;

use pocketmine\api\command\CommandSender;

/**
 * /ban-ip <ip> [reason] — ban a raw IP (persisted to banned-ips.txt) and
 * kick every session currently connected from it.
 */
final class BanIpCommand extends BuiltinCommand {
    public function __construct() {
        parent::__construct(
            'ban-ip',
            'Ban an IP address',
            '/ban-ip <ip> [reason]',
            [],
            'khronos.command.ban-ip',
            category: 'admin',
        );
    }

    public function execute(CommandSender $sender, array $args): bool {
        $ip = array_shift($args);
        if ($ip === null || $ip === '') {
            $sender->sendMessage('Usage: /ban-ip <ip> [reason]');
            return false;
        }
        $lists = $this->playerLists();
        if ($lists === null) {
            return false;
        }
        $lists->banIp($ip);
        $kernel = $this->kernel();
        if ($kernel !== null) {
            $reason = implode(' ', $args) ?: 'IP banned';
            $kicked = $kernel->getNetworkSessionService()->kickByIp($ip, $reason);
            if ($kicked > 0) {
                $sender->sendMessage("Kicked $kicked session(s) from $ip");
            }
        }
        $sender->sendMessage("Banned IP $ip");
        return true;
    }
}

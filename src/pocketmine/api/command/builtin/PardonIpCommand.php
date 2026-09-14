<?php

declare(strict_types=1);

namespace pocketmine\api\command\builtin;

use pocketmine\api\command\CommandSender;
use pocketmine\api\command\Format;

/**
 * /pardon-ip <ip> (alias /unban-ip) — lift an IP ban AND the wire-layer
 * packet-flood block. The wire block lives in the RakLib thread (set when
 * an IP exceeds the packet limit), so unbanning in the player list alone
 * would leave the address silently unable to reach the server.
 */
final class PardonIpCommand extends BuiltinCommand {
    public function __construct() {
        parent::__construct(
            'pardon-ip',
            'Unban an IP address',
            '/pardon-ip <ip>',
            ['unban-ip'],
            'khronos.command.pardon-ip',
            category: 'admin',
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

        // Lift the RakLib wire-layer block for the same address, if any
        // (no-op when the server runs without networking).
        $kernel = $this->kernel();
        if ($kernel !== null) {
            $adapter = $kernel->getNetworkPort();
            if ($adapter instanceof \pocketmine\adapter\driven\network\Protocol84NetworkAdapter) {
                $adapter->unblockAddress($ip);
            }
        }

        $sender->sendMessage(Format::success('Pardoned IP ' . Format::VALUE . $ip . Format::SUCCESS . '.'));
        return true;
    }
}

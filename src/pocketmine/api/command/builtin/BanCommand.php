<?php

declare(strict_types=1);

namespace pocketmine\api\command\builtin;

use pocketmine\api\command\CommandSender;

/**
 * /ban <player> [reason] — ban by name (and recorded uuid). Online players
 * are kicked immediately.
 */
final class BanCommand extends BuiltinCommand {
    public function __construct() {
        parent::__construct(
            'ban',
            'Ban a player',
            '/ban <player> [reason]',
            [],
            'khronos.command.ban',
        );
    }

    public function execute(CommandSender $sender, array $args): bool {
        $name = array_shift($args);
        if ($name === null || $name === '') {
            $sender->sendMessage('Usage: /ban <player> [reason]');
            return false;
        }
        $lists = $this->playerLists();
        if ($lists === null) {
            return false;
        }
        $lists->ban($name);
        $reason = implode(' ', $args) ?: 'Banned by an operator';
        $kernel = $this->kernel();
        if ($kernel !== null) {
            $id = $this->findOnlineId($name);
            if ($id !== null) {
                $kernel->getNetworkSessionService()->kick($id, $reason);
            }
        }
        $sender->sendMessage("Banned $name");
        return true;
    }
}

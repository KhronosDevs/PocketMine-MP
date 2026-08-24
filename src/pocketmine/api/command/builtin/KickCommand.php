<?php

declare(strict_types=1);

namespace pocketmine\api\command\builtin;

use pocketmine\api\command\CommandSender;
use pocketmine\api\command\Format;

/**
 * /kick <player> [reason] — disconnect an online player immediately (no ban
 * is written; they may rejoin). The target is resolved by name
 * (case-insensitive); a missing name targets the sender when they are a
 * player. Mirrors the ban command's kick, minus the ban-list write.
 */
final class KickCommand extends BuiltinCommand {
    public function __construct() {
        parent::__construct(
            'kick',
            'Kick a player',
            '/kick <player> [reason]',
            [],
            'khronos.command.kick',
            category: 'admin',
        );
    }

    public function execute(CommandSender $sender, array $args): bool {
        $name = array_shift($args);
        $kernel = $this->kernel();
        if ($kernel === null) {
            return false;
        }
        $id = $this->resolvePlayerId($sender, $name);
        if ($id === null) {
            $sender->sendMessage($name === null || $name === ''
                ? 'Usage: /kick <player> [reason]'
                : "Player $name not found");
            return false;
        }
        $reason = implode(' ', $args) ?: 'Kicked by an operator';
        $target = ($name === null || $name === '') ? $this->playerName($id) : $name;
        if ($kernel->getNetworkSessionService()->kick($id, $reason)) {
            $sender->sendMessage(Format::success('Kicked ' . Format::VALUE . $target . Format::SUCCESS . ' ' . Format::MUTED . "($reason)"));
            return true;
        }
        $sender->sendMessage(Format::error('Could not kick ' . Format::VALUE . $target));
        return false;
    }
}

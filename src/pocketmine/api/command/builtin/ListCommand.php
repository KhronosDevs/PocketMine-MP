<?php

declare(strict_types=1);

namespace pocketmine\api\command\builtin;

use pocketmine\api\command\CommandSender;
use pocketmine\api\command\Format;

/**
 * /list — show who is online. Available to everyone (like legacy /list).
 */
final class ListCommand extends BuiltinCommand {
    public function __construct() {
        parent::__construct(
            'list',
            'List players online',
            '/list',
            ['players'],
            'khronos.command.list',
            category: 'general',
        );
    }

    public function execute(CommandSender $sender, array $args): bool {
        $kernel = $this->kernel();
        if ($kernel === null) {
            return false;
        }
        $players = $kernel->getNetworkSessionService()->getOnlinePlayers();
        $max = $kernel->getWorldRegistry()->getConfig(0)?->maxPlayers ?? 20;
        $sender->sendMessage(Format::header('Players Online') . ' ' . Format::VALUE . count($players) . Format::MUTED . '/' . $max . Format::RESET);
        if ($players !== []) {
            $names = [];
            foreach ($players as $p) {
                $names[] = $p['username'];
            }
        $lines = [];
            foreach ($players as $p) {
                $lines[] = Format::GREEN . $p['username'] . Format::RESET;
            }
            $sender->sendMessage(Format::MUTED . implode(', ', $lines) . Format::RESET);
        }
        return true;
    }
}

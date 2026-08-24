<?php

declare(strict_types=1);

namespace pocketmine\api\command\builtin;

use pocketmine\api\command\CommandSender;
use pocketmine\api\command\Format;

/**
 * /loadall — loads every on-disk world that isn't currently loaded.
 * Reports success/failure per world with colored output.
 */
final class LoadAllCommand extends BuiltinCommand {
    public function __construct() {
        parent::__construct(
            'loadall',
            'Load all unloaded worlds',
            '/loadall',
            [],
            'khronos.command.loadall',
            category: 'world',
        );
    }

    public function execute(CommandSender $sender, array $args): bool {
        $server = \pocketmine\api\server\Server::getInstance();
        $unloaded = $server->getUnloadedWorlds();

        if ($unloaded === []) {
            $sender->sendMessage(Format::success('No unloaded worlds found.'));
            return true;
        }

        $loaded = 0;
        $failed = 0;
        foreach ($unloaded as $name) {
            try {
                $world = $server->loadWorld($name);
                $sp = $world->getSpawnLocation();
                $sender->sendMessage(
                    Format::SUCCESS . '* '
                    . Format::VALUE . $name
                    . Format::SUCCESS . ' loaded (spawn '
                    . Format::VALUE . $sp['x'] . ', ' . $sp['y'] . ', ' . $sp['z']
                    . Format::SUCCESS . ')'
                );
                $loaded++;
            } catch (\RuntimeException $e) {
                $sender->sendMessage(
                    Format::ERROR . '* '
                    . Format::VALUE . $name
                    . Format::ERROR . ' failed: ' . $e->getMessage()
                );
                $failed++;
            }
        }

        $sender->sendMessage(
            Format::success(
                'Done — '
                . Format::VALUE . $loaded
                . Format::SUCCESS . ' loaded, '
                . Format::VALUE . $failed
                . Format::SUCCESS . ' failed.'
            )
        );
        return true;
    }
}

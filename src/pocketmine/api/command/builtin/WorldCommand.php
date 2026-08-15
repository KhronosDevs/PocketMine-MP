<?php

declare(strict_types=1);

namespace pocketmine\api\command\builtin;

use pocketmine\api\command\CommandSender;
use pocketmine\core\enum\GeneratorType;

/**
 * 14.20 multi-world:
 *   /world            — list loaded worlds
 *   /world <name>     — teleport the sender to that world's spawn
 *   /world create <name> [seed] — generate a brand-new world
 *
 * Switching re-points the player's session at the target world bundle: the
 * chunk stream, entity broadcast and time all switch with them.
 */
final class WorldCommand extends BuiltinCommand {
    public function __construct() {
        parent::__construct(
            'world',
            'List, switch, or create worlds',
            '/world [list|create <name> [seed] [generator]] [name]',
            ['worlds'],
            'khronos.command.world',
        );
    }

    public function execute(CommandSender $sender, array $args): bool {
        $kernel = $this->kernel();
        if ($kernel === null) {
            return false;
        }
        $server = \pocketmine\api\server\Server::getInstance();

        // /world create <name> [seed] [generator]
        if (($args[0] ?? '') === 'create') {
            $name = $args[1] ?? '';
            if ($name === '') {
                $sender->sendMessage('Usage: /world create <name> [seed] [generator]');
                return false;
            }
            $seed = isset($args[2]) && is_numeric($args[2]) ? (int)$args[2] : 0;
            $generator = GeneratorType::tryFrom($args[3] ?? '');
            if ($generator === null) {
                $sender->sendMessage("Unknown generator '{$args[3]}'. Use normal, flat or void.");
                return false;
            }
            $world = $server->generateWorld($name, $seed, $generator);
            $sender->sendMessage("World '{$world->getName()}' created (seed {$world->getSeed()}, generator {$generator->value}).");
            return true;
        }

        // /world <name> — switch the sender (must be a player).
        if (isset($args[0]) && $args[0] !== '' && $args[0] !== 'list') {
            $selfId = $this->selfId($sender);
            if ($selfId === null) {
                $sender->sendMessage('Only players can switch worlds.');
                return false;
            }
            $world = $server->getWorldByName($args[0]);
            if ($world === null) {
                $sender->sendMessage("World '{$args[0]}' is not loaded.");
                return false;
            }
            if ($kernel->getNetworkSessionService()->switchWorld($selfId, $world->getWorldId())) {
                $sender->sendMessage("Switched to world '{$world->getName()}'.");
                return true;
            }
            $sender->sendMessage('Could not switch worlds.');
            return false;
        }

        // /world (list)
        $worlds = $server->getWorlds();
        if ($worlds === []) {
            $sender->sendMessage('No worlds loaded.');
            return true;
        }
        $default = $server->getDefaultWorld();
        foreach ($worlds as $world) {
            $marker = $world->getWorldId() === $default->getWorldId() ? ' (default)' : '';
            $sender->sendMessage(
                $world->getWorldId() . ': ' . $world->getName()
                . ' [' . $world->getFolderName() . ']'
                . $marker
            );
        }
        return true;
    }
}

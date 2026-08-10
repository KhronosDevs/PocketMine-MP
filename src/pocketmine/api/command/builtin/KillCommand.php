<?php

declare(strict_types=1);

namespace pocketmine\api\command\builtin;

use pocketmine\api\command\CommandSender;
use pocketmine\core\ecs\EntityRef;

/**
 * /kill [player] — kills the target through the full combat pipeline (death
 * events, loot drops). Players stay in the world as a corpse and can be
 * revived by respawning, like any other death.
 */
final class KillCommand extends BuiltinCommand {
    public function __construct() {
        parent::__construct(
            'kill',
            'Kill yourself or another player',
            '/kill [player]',
            [],
        );
    }

    public function execute(CommandSender $sender, array $args): bool {
        $targetId = $this->resolvePlayerId($sender, array_shift($args) ?? null);
        if ($targetId === null) {
            $sender->sendMessage('Player not found.');
            return false;
        }

        $kernel = $this->kernel();
        if ($kernel === null) {
            return false;
        }
        $world = $kernel->getWorld();
        if ($world->getEntity($targetId) === null) {
            return false;
        }

        $kernel->getCombatService()->kill(EntityRef::create($targetId, $world));

        $name = $this->playerName($targetId);
        $sender->sendMessage("Killed $name.");
        return true;
    }
}

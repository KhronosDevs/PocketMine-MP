<?php

declare(strict_types=1);

namespace pocketmine\api\command\builtin;

use pocketmine\api\command\CommandSender;
use pocketmine\api\command\Format;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\WorldComponent;

/**
 * /setspawn — pins the world spawn to the sender's feet position (rounded).
 *
 * Writes the per-world WorldConfig (so /world <name> switching and the
 * safe-spawn fallback land there) and, for the default world (id 0), the
 * ServerConfig that join/respawn read — keeping every spawn path consistent.
 * The change is persisted to level.dat on the next save.
 */
final class SetSpawnCommand extends BuiltinCommand {
    public function __construct() {
        parent::__construct(
            'setspawn',
            'Set the world spawn to your position',
            '/setspawn',
            [],
            'khronos.command.setspawn',
            category: 'player',
        );
    }

    public function execute(CommandSender $sender, array $args): bool {
        $selfId = $this->selfId($sender);
        if ($selfId === null) {
            $sender->sendMessage('Only players can set the spawn.');
            return false;
        }
        $kernel = $this->kernel();
        if ($kernel === null) {
            return false;
        }
        $entity = $kernel->getWorld()->getEntity($selfId);
        $pos = $entity?->get(PositionComponent::class);
        if ($pos === null) {
            $sender->sendMessage('Could not read your position.');
            return false;
        }
        $worldComponent = $entity?->get(WorldComponent::class);
        $worldId = $worldComponent instanceof WorldComponent ? $worldComponent->id : 0;

        $server = \pocketmine\api\server\Server::getInstance();
        $world = $server->getWorldById($worldId);
        if ($world === null) {
            $sender->sendMessage('Could not find your current world.');
            return false;
        }

        $x = (int)floor($pos->x);
        $y = (int)floor($pos->y);
        $z = (int)floor($pos->z);

        // Per-world config (honored by /world <name> switching and the
        // safe-spawn fallback in NetworkSessionService::safeSpawnFor).
        $world->setSpawnLocation($x, $y, $z);

        // Default world: join + respawn read the ServerConfig, so mirror the
        // spawn there too (khronos.json does the same when it overrides).
        if ($worldId === 0) {
            $config = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ServerConfig::class);
            if ($config instanceof \pocketmine\core\resource\ServerConfig) {
                $config->spawnX = $x;
                $config->spawnY = $y;
                $config->spawnZ = $z;
            }
        }

        // Persist now so a crash/restart keeps the new spawn.
        $kernel->saveAllWorlds();

        $name = $world->getName();
        $sender->sendMessage(Format::success("Spawn of '"
            . Format::VALUE . $name . Format::SUCCESS . "' set to "
            . Format::VALUE . "$x, $y, $z" . Format::SUCCESS . '.'));
        return true;
    }
}

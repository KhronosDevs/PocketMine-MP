<?php

declare(strict_types=1);

namespace pocketmine\api\command\builtin;

use pocketmine\api\command\CommandSender;
use pocketmine\core\component\PositionComponent;

/**
 * /tp <x y z> or /tp <player> <x y z> — moves a player to world coordinates.
 * The position is set on the ECS component (so the per-tick entity broadcast
 * shows the jump to every viewer) and an immediate MovePlayerPacket
 * (MODE_RESET) teleports the actor.
 */
final class TeleportCommand extends BuiltinCommand {
    public function __construct() {
        parent::__construct(
            'teleport',
            'Teleport a player to coordinates',
            '/tp <x y z> | /tp <player> <x y z>',
            ['tp'],
            'khronos.command.tp',
        );
    }

    public function execute(CommandSender $sender, array $args): bool {
        $targetName = null;
        $coords = $args;
        if (isset($coords[0]) && !is_numeric($coords[0])) {
            $targetName = array_shift($coords);
        }
        if (count($coords) < 3) {
            $sender->sendMessage($this->usage);
            return false;
        }

        $x = (float)$coords[0];
        $y = (float)$coords[1];
        $z = (float)$coords[2];
        if (!is_finite($x) || !is_finite($y) || !is_finite($z) || abs($x) > 1_000_000 || abs($z) > 1_000_000) {
            $sender->sendMessage('Invalid coordinates.');
            return false;
        }
        $y = max(0.0, $y);

        $targetId = $this->resolvePlayerId($sender, $targetName);
        if ($targetId === null) {
            $sender->sendMessage('Player not found.');
            return false;
        }

        $kernel = $this->kernel();
        if ($kernel === null) {
            return false;
        }
        $pos = $kernel->getWorld()->getEntity($targetId)?->get(PositionComponent::class);
        if ($pos === null) {
            return false;
        }

        // Blocker 4 audit: cancellable EntityTeleportEvent fires before the
        // position mutates.
        $event = new \pocketmine\api\event\EntityTeleportEvent(
            new \pocketmine\api\entity\Player(
                \pocketmine\core\ecs\EntityRef::create($targetId, $kernel->getWorld()),
                $kernel->getWorld(),
            ),
            [(float)$pos->x, (float)$pos->y, (float)$pos->z],
            [$x, $y, $z],
        );
        $kernel->getEventPort()->emit($event);
        if ($event->isCancelled()) {
            $sender->sendMessage('The teleport was cancelled.');
            return false;
        }
        [$x, $y, $z] = $event->getTo();
        $pos->x = $x;
        $pos->y = $y;
        $pos->z = $z;
        $kernel->getNetworkSessionService()->sendTeleportTo($targetId, $x, $y, $z);

        $name = $this->playerName($targetId);
        $sender->sendMessage("Teleported $name to $x, $y, $z.");
        return true;
    }
}

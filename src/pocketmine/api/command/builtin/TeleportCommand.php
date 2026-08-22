<?php

declare(strict_types=1);

namespace pocketmine\api\command\builtin;

use pocketmine\api\command\CommandSender;
use pocketmine\core\component\PositionComponent;

/**
 * /tp <x y z> | /tp <player> <x y z> — moves a player to world coordinates.
 * /tp <player1> <player2> — moves player1 to player2's position.
 * The position is set on the ECS component (so the per-tick entity broadcast
 * shows the jump to every viewer) and an immediate MovePlayerPacket
 * (MODE_RESET) teleports the actor.
 */
final class TeleportCommand extends BuiltinCommand {
    public function __construct() {
        parent::__construct(
            'teleport',
            'Teleport a player to coordinates or to another player',
            '/tp <x y z> | /tp <player> <x y z> | /tp <player1> <player2>',
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

        // Bug 36: /tp <player1> <player2> — teleport player1 to player2's
        // position (both non-numeric args means two names).
        if ($targetName !== null && isset($coords[0]) && !is_numeric($coords[0])) {
            return $this->teleportToPlayer($sender, $targetName, array_shift($coords));
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

        // Bug 37: find a safe Y if the destination is inside solid terrain.
        // Scans upward from the floor until two consecutive air blocks are
        // found (feet + head). Capped at world height.
        $store = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);
        if ($store !== null) {
            $bx = (int)floor($x);
            $bz = (int)floor($z);
            $sy = max(1, min(254, (int)floor($y)));
            while ($sy < 255) {
                if ($store->getBlock($bx, $sy, $bz) === 0 && $store->getBlock($bx, $sy + 1, $bz) === 0) {
                    break;
                }
                $sy++;
            }
            $y = max((float)$y, (float)$sy); // never go lower than requested
        }

        $pos->x = $x;
        $pos->y = $y;
        $pos->z = $z;
        $kernel->getNetworkSessionService()->sendTeleportTo($targetId, $x, $y, $z);

        $name = $this->playerName($targetId);
        $sender->sendMessage("Teleported $name to $x, $y, $z.");
        return true;
    }

    /**
     * Bug 36: /tp <player1> <player2> — moves player1 to player2's position.
     * More convenient than typing coordinates, especially on a phone.
     */
    private function teleportToPlayer(CommandSender $sender, string $fromName, string $toName): bool {
        $kernel = $this->kernel();
        if ($kernel === null) {
            return false;
        }

        $fromId = $this->resolvePlayerId($sender, $fromName);
        $toId = $this->resolvePlayerId($sender, $toName);
        if ($fromId === null || $toId === null) {
            $sender->sendMessage('Player not found.');
            return false;
        }
        if ($fromId === $toId) {
            $sender->sendMessage('Cannot teleport a player to themselves.');
            return false;
        }

        $toPos = $kernel->getWorld()->getEntity($toId)?->get(PositionComponent::class);
        if ($toPos === null) {
            return false;
        }

        return $this->execute($sender, [
            $fromName,
            (string)round($toPos->x + 0.5),
            (string)round($toPos->y),
            (string)round($toPos->z + 0.5),
        ]);
    }
}

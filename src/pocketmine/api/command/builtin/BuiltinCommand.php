<?php

declare(strict_types=1);

namespace pocketmine\api\command\builtin;

use pocketmine\api\command\Command;
use pocketmine\api\command\CommandSender;
use pocketmine\api\command\PlayerCommandSender;
use pocketmine\Kernel;

/**
 * Shared plumbing for the server's builtin commands: lazy kernel access and
 * player resolution (an explicit name matches an online player, an absent
 * name targets the sender).
 */
abstract class BuiltinCommand extends Command {
    protected function kernel(): ?Kernel {
        return Kernel::getInstance();
    }

    /** The sender's own entity id when they are a player, else null. */
    protected function selfId(CommandSender $sender): ?int {
        return $sender instanceof PlayerCommandSender ? $sender->entityId : null;
    }

    /**
     * Resolve a command target: an explicit name looks up an online player
     * (case-insensitive); null/'' means the sender themself.
     */
    protected function resolvePlayerId(CommandSender $sender, ?string $name): ?int {
        if ($name === null || $name === '') {
            return $this->selfId($sender);
        }
        $kernel = $this->kernel();
        if ($kernel === null) {
            return null;
        }
        foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
            if (strcasecmp($p['username'], $name) === 0) {
                return $p['entityId'];
            }
        }
        return null;
    }

    /** Display name of an online player by entity id (fallback 'Player'). */
    protected function playerName(int $entityId): string {
        $kernel = $this->kernel();
        if ($kernel === null) {
            return 'Player';
        }
        foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
            if ($p['entityId'] === $entityId) {
                return $p['username'];
            }
        }
        return 'Player';
    }
}

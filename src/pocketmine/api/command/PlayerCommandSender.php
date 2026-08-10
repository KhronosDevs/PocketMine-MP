<?php

declare(strict_types=1);

namespace pocketmine\api\command;

use pocketmine\api\entity\Player;
use pocketmine\api\permission\PermissionManager;
use pocketmine\Kernel;

/**
 * A CommandSender backed by an online player session. Built by
 * NetworkSessionService when a player sends a chat line starting with '/';
 * command output is routed back to that player as a raw TextPacket.
 */
final class PlayerCommandSender implements CommandSender {
    public readonly int $entityId;
    public readonly string $username;

    /**
     * Accepts either the raw session identity (int id + name) or an api
     * Player facade (id/name are derived from it).
     */
    public function __construct(int|Player $entityId, ?string $username = null) {
        if ($entityId instanceof Player) {
            $username ??= $entityId->getName();
            $entityId = $entityId->getId();
        }
        $this->entityId = $entityId;
        $this->username = $username ?? (string)$entityId;
    }

    public function sendMessage(string $message): void {
        Kernel::getInstance()?->getNetworkSessionService()?->sendMessageTo($this->entityId, $message);
    }

    public function hasPermission(string $permission): bool {
        $player = $this->getPlayer();
        if ($player === null) {
            return false;
        }
        $manager = Kernel::getInstance()?->getResourceRegistry()?->get(PermissionManager::class);
        return $manager instanceof PermissionManager
            ? $manager->hasPermission($player, $permission)
            : true;
    }

    public function getName(): string {
        return $this->username;
    }

    public function isPlayer(): bool {
        return true;
    }

    public function getPlayer(): ?Player {
        $kernel = Kernel::getInstance();
        if ($kernel === null || $kernel->getWorld()->getEntity($this->entityId) === null) {
            return null;
        }
        $world = $kernel->getWorld();
        $ref = \pocketmine\core\ecs\EntityRef::create($this->entityId, $world);
        $entity = Player::wrap($ref, $world);
        return $entity instanceof Player ? $entity : null;
    }
}

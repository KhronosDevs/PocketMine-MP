<?php

declare(strict_types=1);

namespace pocketmine\plugin\api\command;

use pocketmine\domain\ecs\EntityRef;
use pocketmine\domain\component\MetadataComponent;
use pocketmine\plugin\api\event\EventBus;

final class PlayerCommandSender implements CommandSender {
    public function __construct(
        private readonly EntityRef $player,
        private readonly EventBus $eventBus,
    ) {}

    public function sendMessage(string $message): void {
        // Would send message to player via NetworkPort
        // For now, just echo
        echo "[Player {$this->player->getId()}] $message\n";
    }

    public function hasPermission(string $permission): bool {
        $entity = $this->player->getEntity();
        if (!$entity) return false;
        
        $metadata = $entity->get(\pocketmine\domain\component\MetadataComponent::class);
        if (!$metadata) return false;
        
        $permissions = $metadata->get('permissions', []);
        return in_array($permission, $permissions) || in_array('*', $permissions);
    }

    public function getName(): string {
        $entity = $this->player->getEntity();
        if (!$entity) return "Unknown";
        
        $metadata = $entity->get(\pocketmine\domain\component\MetadataComponent::class);
        if (!$metadata) return "Unknown";
        
        return $metadata->get('username', 'Player_' . $this->player->getId());
    }

    public function isPlayer(): bool {
        return true;
    }

    public function getPlayer(): ?EntityRef {
        return $this->player;
    }
}

final class ConsoleCommandSender implements CommandSender {
    public function sendMessage(string $message): void {
        echo "[CONSOLE] $message\n";
    }

    public function hasPermission(string $permission): bool {
        return true; // Console has all permissions
    }

    public function getName(): string {
        return "CONSOLE";
    }

    public function isPlayer(): bool {
        return false;
    }

    public function getPlayer(): ?EntityRef {
        return null;
    }
}
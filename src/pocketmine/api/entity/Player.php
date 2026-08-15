<?php

declare(strict_types=1);

namespace pocketmine\api\entity;

use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;
use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\AttributeComponent;
use pocketmine\core\component\EffectComponent;
use pocketmine\core\component\tags\PlayerTag;

class Player extends Entity
{
    public function __construct(EntityRef $ref, World $world)
    {
        parent::__construct($ref, $world);
    }

    public function getName(): string
    {
        $metadata = $this->getMetadata();
        return $metadata->get(\pocketmine\core\constants\MetadataKeys::USERNAME, 'Player_' . $this->getId());
    }

    public function setName(string $name): void
    {
        $this->getMetadata()->set(\pocketmine\core\constants\MetadataKeys::USERNAME, $name);
    }

    public function getDisplayName(): string
    {
        $metadata = $this->getMetadata();
        return $metadata->get(\pocketmine\core\constants\MetadataKeys::DISPLAY_NAME, $this->getName());
    }

    public function setDisplayName(string $name): void
    {
        $this->getMetadata()->set(\pocketmine\core\constants\MetadataKeys::DISPLAY_NAME, $name);
    }

    public function getSkin(): array
    {
        $metadata = $this->getMetadata();
        return [
            'skinId' => $metadata->get(\pocketmine\core\constants\MetadataKeys::SKIN_ID, ''),
            'skinData' => $metadata->get(\pocketmine\core\constants\MetadataKeys::SKIN_DATA, ''),
        ];
    }

    public function setSkin(string $skinId, string $skinData): void
    {
        $metadata = $this->getMetadata();
        $metadata->set(\pocketmine\core\constants\MetadataKeys::SKIN_ID, $skinId);
        $metadata->set(\pocketmine\core\constants\MetadataKeys::SKIN_DATA, $skinData);
    }

    public function getGamemode(): int
    {
        $metadata = $this->getMetadata();
        return \pocketmine\core\enum\GameMode::coerce($metadata->get(\pocketmine\core\constants\MetadataKeys::GAMEMODE))->value;
    }

    public function setGamemode(int $gamemode): void
    {
        $metadata = $this->getMetadata();
        $metadata->set(\pocketmine\core\constants\MetadataKeys::GAMEMODE, \pocketmine\core\enum\GameMode::coerce($gamemode)->value);
    }

    public function getExperience(): int
    {
        $metadata = $this->getMetadata();
        return $metadata->get(\pocketmine\core\constants\MetadataKeys::EXPERIENCE, 0);
    }

    public function setExperience(int $exp): void
    {
        $metadata = $this->getMetadata();
        $metadata->set(\pocketmine\core\constants\MetadataKeys::EXPERIENCE, $exp);
    }

    public function getLevel(): int
    {
        $attributes = $this->getAttributes();
        return (int)$attributes->get(\pocketmine\core\constants\AttributeKeys::EXPERIENCE_LEVEL);
    }

    public function setLevel(int $level): void
    {
        $attributes = $this->getAttributes();
        $attributes->set(\pocketmine\core\constants\AttributeKeys::EXPERIENCE_LEVEL, (float)$level);
    }

    public function getFoodLevel(): float
    {
        $attributes = $this->getAttributes();
        return $attributes->get(\pocketmine\core\constants\AttributeKeys::HUNGER);
    }

    public function setFoodLevel(float $level): void
    {
        $attributes = $this->getAttributes();
        $attributes->set(\pocketmine\core\constants\AttributeKeys::HUNGER, max(0, min(20, $level)));
    }

    public function getSaturation(): float
    {
        $attributes = $this->getAttributes();
        return $attributes->get(\pocketmine\core\constants\AttributeKeys::SATURATION);
    }

    public function getExhaustion(): float
    {
        $attributes = $this->getAttributes();
        return $attributes->get(\pocketmine\core\constants\AttributeKeys::EXHAUSTION);
    }

    public function isOp(): bool
    {
        return $this->hasPermission('pocketmine.op');
    }

    public function setOp(bool $op): void
    {
        $metadata = $this->getMetadata();
        $perms = $metadata->get(\pocketmine\core\constants\MetadataKeys::PERMISSIONS, []);
        if ($op) {
            if (!in_array('pocketmine.op', $perms)) {
                $perms[] = 'pocketmine.op';
            }
        } else {
            $perms = array_diff($perms, ['pocketmine.op']);
        }
        $metadata->set(\pocketmine\core\constants\MetadataKeys::PERMISSIONS, $perms);
    }

    public function hasPermission(string $permission): bool
    {
        // Route through the server-wide manager so plugin.yml defaults and
        // child permissions resolve, not just explicit metadata grants.
        $kernel = \pocketmine\Kernel::getInstance();
        if ($kernel !== null) {
            return $kernel->getPermissionManager()->hasPermission($this, $permission);
        }
        $metadata = $this->getMetadata();
        $perms = $metadata->get(\pocketmine\core\constants\MetadataKeys::PERMISSIONS, []);
        return in_array($permission, $perms) || in_array('*', $perms) || in_array('pocketmine.op', $perms);
    }

    public function sendMessage(string $message): void
    {
        $this->sendTextPacket(0, $message); // raw message
    }

    public function sendTip(string $message): void
    {
        $this->sendTextPacket(4, $message); // tip
    }

    public function sendPopup(string $message): void
    {
        $this->sendTextPacket(5, $message); // popup
    }

    private function sendTextPacket(int $type, string $message): void
    {
        $kernel = \pocketmine\Kernel::getInstance();
        if ($kernel === null) {
            return;
        }
        $ref = new \pocketmine\port\driven\PlayerRef(
            $this->getUniqueId(),
            $this->getId(),
            $this->getName()
        );
        $packet = new \pocketmine\protocol\TextPacket();
        $packet->type = $type;
        $packet->message = $message;
        $kernel->getNetworkPort()->sendPacket($ref, $packet);
    }

    public function getInventory(): InventoryComponent
    {
        return parent::getInventory();
    }

    public function getEnderChestInventory(): InventoryComponent
    {
        $metadata = $this->getMetadata();
        $enderChest = $metadata->get(\pocketmine\core\constants\MetadataKeys::ENDER_CHEST_INVENTORY);
        if (!$enderChest) {
            $enderChest = new \pocketmine\core\component\InventoryComponent(27);
            $metadata->set(\pocketmine\core\constants\MetadataKeys::ENDER_CHEST_INVENTORY, $enderChest);
        }
        return $enderChest;
    }

    public function getExperienceProgress(): float
    {
        $attributes = $this->getAttributes();
        $level = (int)$attributes->get(\pocketmine\core\constants\AttributeKeys::EXPERIENCE_LEVEL);
        if ($level <= 0) return 0;
        $xp = $attributes->get(\pocketmine\core\constants\AttributeKeys::EXPERIENCE);
        $required = $this->getXpRequiredForLevel($level);
        return $required > 0 ? $xp / $required : 0;
    }

    private function getXpRequiredForLevel(int $level): int
    {
        if ($level >= 30) return 112 + ($level - 30) * 9;
        if ($level >= 15) return 37 + ($level - 15) * 5;
        return 7 + $level * 2;
    }

    public function giveExperience(int $amount): void
    {
        $this->setExperience($this->getExperience() + $amount);
    }

    public function addExperienceLevel(int $levels): void
    {
        $this->setLevel($this->getLevel() + $levels);
    }

    public function takeExperienceLevel(int $levels): void
    {
        $this->setLevel(max(0, $this->getLevel() - $levels));
    }

    public function setHealth(float $health): void
    {
        $healthComp = $this->getHealthComponent();
        if ($healthComp) {
            $healthComp->current = max(0, min($healthComp->max, $health));
        }
    }

    public function isAlive(): bool
    {
        return !$this->isDead() && $this->getHealth() > 0;
    }

    public function isOnline(): bool
    {
        $metadata = $this->getMetadata();
        return $metadata->get(\pocketmine\core\constants\MetadataKeys::ONLINE, false);
    }

    public function setOnline(bool $online): void
    {
        $metadata = $this->getMetadata();
        $metadata->set(\pocketmine\core\constants\MetadataKeys::ONLINE, $online);
    }

    public function getAddress(): string
    {
        $metadata = $this->getMetadata();
        return $metadata->get(\pocketmine\core\constants\MetadataKeys::ADDRESS, '');
    }

    public function getClientId(): int
    {
        $metadata = $this->getMetadata();
        return $metadata->get(\pocketmine\core\constants\MetadataKeys::CLIENT_ID, 0);
    }

    public function getPing(): int
    {
        $metadata = $this->getMetadata();
        return $metadata->get(\pocketmine\core\constants\MetadataKeys::PING, 0);
    }

    public function kick(string $reason = ''): void
    {
        $kernel = \pocketmine\Kernel::getInstance();
        if ($kernel !== null) {
            $ref = new \pocketmine\port\driven\PlayerRef(
                $this->getUniqueId(),
                $this->getId(),
                $this->getName()
            );
            $kernel->getNetworkPort()->disconnect($ref, $reason);
        }
        $this->getMetadata()->set(\pocketmine\core\constants\MetadataKeys::ONLINE, false);
    }

    public function teleport(float $x, float $y, float $z, float $yaw = 0, float $pitch = 0): bool
    {
        return parent::teleport($x, $y, $z, $yaw, $pitch);
    }

    public function addExperience(int $amount): void
    {
        $this->giveExperience($amount);
    }

    public function getAbsorption(): float
    {
        $metadata = $this->getMetadata();
        return (float)$metadata->get(\pocketmine\core\constants\MetadataKeys::ABSORPTION, 0);
    }

    public function setAbsorption(float $amount): void
    {
        $this->getMetadata()->set(\pocketmine\core\constants\MetadataKeys::ABSORPTION, max(0.0, $amount));
    }
}


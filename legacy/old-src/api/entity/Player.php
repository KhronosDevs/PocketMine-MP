<?php

declare(strict_types=1);

namespace pocketmine\api\entity;

use pocketmine\domain\ecs\EntityRef;
use pocketmine\domain\ecs\World;
use pocketmine\domain\component\InventoryComponent;
use pocketmine\domain\component\MetadataComponent;
use pocketmine\domain\component\AttributeComponent;
use pocketmine\domain\component\EffectComponent;
use pocketmine\domain\component\tags\PlayerTag;

class Player extends Entity {
    public function __construct(EntityRef $ref, World $world) {
        parent::__construct($ref, $world);
    }

    public function getName(): string {
        $metadata = $this->getMetadata();
        return $metadata->get('username', 'Player_' . $this->getId());
    }

    public function setName(string $name): void {
        $this->getMetadata()->set('username', $name);
    }

    public function getDisplayName(): string {
        $metadata = $this->getMetadata();
        return $metadata->get('displayName', $this->getName());
    }

    public function setDisplayName(string $name): void {
        $this->getMetadata()->set('displayName', $name);
    }

    public function getSkin(): array {
        $metadata = $this->getMetadata();
        return [
            'skinId' => $metadata->get('skinId', ''),
            'skinData' => $metadata->get('skinData', ''),
        ];
    }

    public function setSkin(string $skinId, string $skinData): void {
        $metadata = $this->getMetadata();
        $metadata->set('skinId', $skinId);
        $metadata->set('skinData', $skinData);
    }

    public function getGamemode(): int {
        $metadata = $this->getMetadata();
        return $metadata->get('gamemode', 0);
    }

    public function setGamemode(int $gamemode): void {
        $metadata = $this->getMetadata();
        $metadata->set('gamemode', $gamemode);
    }

    public function getExperience(): int {
        $metadata = $this->getMetadata();
        return $metadata->get('experience', 0);
    }

    public function setExperience(int $exp): void {
        $metadata = $this->getMetadata();
        $metadata->set('experience', $exp);
    }

    public function getLevel(): int {
        $attributes = $this->getAttributes();
        return (int)$attributes->get('experience_level');
    }

    public function setLevel(int $level): void {
        $attributes = $this->getAttributes();
        $attributes->set('experience_level', (float)$level);
    }

    public function getFoodLevel(): float {
        $attributes = $this->getAttributes();
        return $attributes->get('hunger');
    }

    public function setFoodLevel(float $level): void {
        $attributes = $this->getAttributes();
        $attributes->set('hunger', max(0, min(20, $level)));
    }

    public function getSaturation(): float {
        $attributes = $this->getAttributes();
        return $attributes->get('saturation');
    }

    public function getExhaustion(): float {
        $attributes = $this->getAttributes();
        return $attributes->get('exhaustion');
    }

    public function isOp(): bool {
        return $this->hasPermission('pocketmine.op');
    }

    public function setOp(bool $op): void {
        $metadata = $this->getMetadata();
        $perms = $metadata->get('permissions', []);
        if ($op) {
            if (!in_array('pocketmine.op', $perms)) {
                $perms[] = 'pocketmine.op';
            }
        } else {
            $perms = array_diff($perms, ['pocketmine.op']);
        }
        $metadata->set('permissions', $perms);
    }

    public function hasPermission(string $permission): bool {
        return $this->hasPermission($permission);
    }

    public function sendMessage(string $message): void {
        // Would send TextPacket via NetworkPort
    }

    public function sendTip(string $message): void {
        // Would send tip packet
    }

    public function sendPopup(string $message): void {
        // Would send popup packet
    }

    public function getInventory(): InventoryComponent {
        return $this->getInventory();
    }

    public function getEnderChestInventory(): InventoryComponent {
        $metadata = $this->getMetadata();
        $enderChest = $metadata->get('enderChestInventory');
        if (!$enderChest) {
            $enderChest = new \pocketmine\domain\component\InventoryComponent(27);
            $metadata->set('enderChestInventory', $enderChest);
        }
        return $enderChest;
    }

    public function getExperienceProgress(): float {
        $attributes = $this->getAttributes();
        $level = $attributes->get('experience_level');
        if ($level <= 0) return 0;
        $xp = $attributes->get('experience');
        $required = $this->getXpRequiredForLevel($level);
        return $required > 0 ? $xp / $required : 0;
    }

    private function getXpRequiredForLevel(int $level): int {
        if ($level >= 30) return 112 + ($level - 30) * 9;
        if ($level >= 15) return 37 + ($level - 15) * 5;
        return 7 + $level * 2;
    }

    public function giveExperience(int $amount): void {
        $this->setExperience($this->getExperience() + $amount);
    }

    public function addExperienceLevel(int $levels): void {
        $this->setLevel($this->getLevel() + $levels);
    }

    public function takeExperienceLevel(int $levels): void {
        $this->setLevel(max(0, $this->getLevel() - $levels));
    }

    public function getHealth(): float {
        return $this->getHealth()->current;
    }

    public function setHealth(float $health): void {
        $healthComp = $this->getHealth();
        $healthComp->current = max(0, min($healthComp->max, $health));
    }

    public function getMaxHealth(): float {
        return $this->getHealth()->max;
    }

    public function setMaxHealth(float $health): void {
        $healthComp = $this->getHealth();
        $healthComp->max = max(1, $health);
        if ($healthComp->current > $healthComp->max) {
            $healthComp->current = $healthComp->max;
        }
    }

    public function isAlive(): bool {
        return !$this->isDead() && $this->getHealth() > 0;
    }

    public function isOnline(): bool {
        $metadata = $this->getMetadata();
        return $metadata->get('online', false);
    }

    public function setOnline(bool $online): void {
        $metadata = $this->getMetadata();
        $metadata->set('online', $online);
    }

    public function getAddress(): string {
        $metadata = $this->getMetadata();
        return $metadata->get('address', '');
    }

    public function getClientId(): int {
        $metadata = $this->getMetadata();
        return $metadata->get('clientId', 0);
    }

    public function getPing(): int {
        $metadata = $this->getMetadata();
        return $metadata->get('ping', 0);
    }

    public function kick(string $reason = ''): void {
        // Would send DisconnectPacket and close connection
    }

    public function teleport(float $x, float $y, float $z, float $yaw = 0, float $pitch = 0): bool {
        return parent::teleport($x, $y, $z, $yaw, $pitch);
    }

    public function addExperience(int $amount): void {
        $this->giveExperience($amount);
    }

    public function setExperience(int $exp): void {
        $this->setExperience($exp);
    }

    public function getAbsorption(): float {
        $effects = $this->getEffects();
        $absorption = $effects->get(\pocketmine\entity\Effect::ABSORPTION);
        return $absorption ? ($absorption->getAmplifier() + 1) * 4 : 0;
    }

    public function setAbsorption(float $amount): void {
        // Would apply absorption effect
    }
}
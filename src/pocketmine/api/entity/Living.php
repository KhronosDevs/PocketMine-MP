<?php

declare(strict_types=1);

namespace pocketmine\api\entity;

use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;
use pocketmine\core\component\tags\MonsterTag;

abstract class Living extends Entity {
    public function __construct(EntityRef $ref, \pocketmine\core\ecs\World $world) {
        parent::__construct($ref, $world);
    }

    public function isAlive(): bool {
        return !$this->isDead() && $this->getHealthComponent()?->current > 0;
    }

    public function getMaxHealth(): float {
        return $this->getHealthComponent()?->max ?? 20.0;
    }

    public function setMaxHealth(float $health): void {
        $comp = $this->getHealthComponent();
        if ($comp) {
            $comp->max = max(1, $health);
            if ($comp->current > $comp->max) {
                $comp->current = $comp->max;
            }
        }
    }

    public function heal(float $amount): void {
        parent::heal($amount);
    }

    public function damage(float $amount): bool {
        return parent::damage($amount);
    }

    public function attack(Entity $target): bool {
        $interactionService = \pocketmine\Kernel::getInstance()->getEntityInteractionService();
        return $interactionService->attack($this->ref, $target->ref);
    }

    public function interact(Entity $target): bool {
        $interactionService = \pocketmine\Kernel::getInstance()->getEntityInteractionService();
        return $interactionService->interact($this->ref, $target->ref);
    }

    public function getAttribute(string $name): float {
        return $this->getAttributes()->get($name);
    }

    public function setAttribute(string $name, float $value): void {
        $this->getAttributes()->set($name, $value);
    }

    public function addAttributeModifier(string $attribute, string $modifierId, float $amount, int $operation): void {
        $this->getAttributes()->addModifier($attribute, $modifierId, $amount, $operation);
    }

    public function removeAttributeModifier(string $attribute, string $modifierId): void {
        $this->getAttributes()->removeModifier($attribute, $modifierId);
    }

    public function addEffect(int $effectId, int $duration, int $amplifier = 0, bool $ambient = false, bool $particles = true): void {
        $this->getEffects()->add($effectId, $amplifier, $duration, $ambient, $particles);
    }

    public function removeEffect(int $effectId): void {
        $this->getEffects()->remove($effectId);
    }

    public function hasEffect(int $effectId): bool {
        return $this->getEffects()->has($effectId);
    }

    public function getEffect(int $effectId): ?\pocketmine\core\component\EffectInstance {
        return $this->getEffects()->get($effectId);
    }

    public function clearEffects(): void {
        $this->getEffects()->clear();
    }

    public function getActiveEffects(): array {
        return $this->getEffects()->getAll();
    }

    public function isMonster(): bool {
        return $this->ref->hasComponent(\pocketmine\core\component\tags\MonsterTag::class);
    }
}
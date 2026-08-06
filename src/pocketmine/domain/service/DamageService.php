<?php

declare(strict_types=1);

namespace pocketmine\domain\service;

use pocketmine\domain\component\HealthComponent;
use pocketmine\domain\ecs\EntityRef;
use pocketmine\domain\ecs\World;

final class DamageService {
    public function __construct(
        private readonly World $world,
    ) {}

    public function applyDamage(EntityRef $targetRef, float $damage, int $cause = 0): bool {
        $target = $targetRef->getEntity();
        if (!$target) return false;
        
        $health = $target->get(HealthComponent::class);
        if (!$health) return false;
        
        $health->current = max(0, $health->current - $damage);
        
        if ($health->current <= 0) {
            $this->handleDeath($targetRef);
        }
        
        return true;
    }

    public function applyHealing(EntityRef $targetRef, float $amount): void {
        $target = $targetRef->getEntity();
        if (!$target) return;
        
        $health = $target->get(HealthComponent::class);
        if (!$health) return;
        
        $health->current = min($health->max, $health->current + $amount);
    }

    public function setHealth(EntityRef $targetRef, float $health): void {
        $target = $targetRef->getEntity();
        if (!$target) return;
        
        $healthComp = $target->get(HealthComponent::class);
        if (!$healthComp) return;
        
        $healthComp->current = max(0, min($healthComp->max, $health));
        
        if ($healthComp->current <= 0) {
            $this->handleDeath($targetRef);
        }
    }

    private function handleDeath(EntityRef $targetRef): void {
        $target = $targetRef->getEntity();
        if (!$target) return;
        
        // In a full implementation, this would:
        // - Drop experience
        // - Drop loot
        // - Send death event
        // - Despawn entity
        
        $this->world->despawn($target);
    }

    public function getHealth(EntityRef $targetRef): ?HealthComponent {
        $target = $targetRef->getEntity();
        if (!$target) return null;
        
        return $target->get(HealthComponent::class);
    }

    public function isAlive(EntityRef $targetRef): bool {
        $health = $this->getHealth($targetRef);
        return $health !== null && $health->current > 0;
    }

    public function getHealthPercentage(EntityRef $targetRef): float {
        $health = $this->getHealth($targetRef);
        if (!$health) return 0.0;
        
        return $health->max > 0 ? ($health->current / $health->max) : 0.0;
    }
}
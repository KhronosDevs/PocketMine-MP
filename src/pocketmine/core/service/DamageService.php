<?php

declare(strict_types=1);

namespace pocketmine\core\service;

use pocketmine\api\event\EntityDamageEvent;
use pocketmine\core\component\HealthComponent;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;

final class DamageService {
    public function __construct(
        private readonly World $world,
        private readonly CombatService $combatService,
    ) {}

    /**
     * Route damage through the unified combat pipeline (damage event, armor,
     * knockback, death handling + loot drops).
     */
    public function applyDamage(EntityRef $targetRef, float $damage, int $cause = EntityDamageEvent::CAUSE_CUSTOM): bool {
        return $this->combatService->applyDamage($targetRef, $damage, null, $cause);
    }

    public function applyHealing(EntityRef $targetRef, float $amount): void {
        $this->combatService->heal($targetRef, $amount);
    }

    public function setHealth(EntityRef $targetRef, float $health): void {
        $target = $targetRef->getEntity();
        if (!$target) return;

        $healthComp = $target->get(HealthComponent::class);
        if (!$healthComp) return;

        $healthComp->current = max(0, min($healthComp->max, $health));

        if ($healthComp->current <= 0) {
            $this->combatService->kill($targetRef);
        }
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

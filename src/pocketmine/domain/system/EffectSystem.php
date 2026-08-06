<?php

declare(strict_types=1);

namespace pocketmine\domain\system;

use pocketmine\domain\component\EffectComponent;
use pocketmine\domain\ecs\Archetype;
use pocketmine\domain\ecs\ParallelSystem;
use pocketmine\domain\ecs\World;

final class EffectSystem implements ParallelSystem {
    public function run(World $world, float $deltaTime): void {
        // Not used - ParallelSystem uses runParallel
    }

    public function runParallel(Archetype $archetype, float $deltaTime): void {
        $effectsArray = $archetype->getComponentArray(EffectComponent::class);

        if (empty($effectsArray)) {
            return;
        }

        $tickDiff = max(1, (int)round($deltaTime * 20)); // Convert to ticks

        foreach ($effectsArray as $effects) {
            $effects->tick($tickDiff);
        }
    }

    public function getTargetArchetypes(World $world): iterable {
        $query = $world->query()
            ->with(\pocketmine\domain\component\EffectComponent::class)
            ->build();

        $registry = $world->getComponentRegistry();
        return $query->archetypes($registry);
    }
}
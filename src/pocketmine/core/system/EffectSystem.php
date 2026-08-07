<?php

declare(strict_types=1);

namespace pocketmine\core\system;

use pocketmine\core\component\EffectComponent;
use pocketmine\core\ecs\Archetype;
use pocketmine\core\ecs\ParallelSystem;
use pocketmine\core\ecs\World;

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
            ->with(\pocketmine\core\component\EffectComponent::class)
            ->build();

        $registry = $world->getComponentRegistry();
        return $query->archetypes($registry);
    }
}
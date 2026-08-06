<?php

declare(strict_types=1);

namespace pocketmine\domain\system;

use pocketmine\domain\component\EffectComponent;
use pocketmine\domain\ecs\System;
use pocketmine\domain\ecs\World;

final class EffectSystem implements System {
    public function run(World $world, float $deltaTime): void {
        $query = $world->query()
            ->with(EffectComponent::class)
            ->build();

        $tickDiff = max(1, (int)round($deltaTime * 20)); // Convert to ticks

        foreach ($query as $entity) {
            $effects = $entity->get(EffectComponent::class);
            $effects->tick($tickDiff);
        }
    }
}
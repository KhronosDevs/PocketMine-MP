<?php

declare(strict_types=1);

namespace pocketmine\domain\system;

use pocketmine\domain\component\PositionComponent;
use pocketmine\domain\component\VelocityComponent;
use pocketmine\domain\component\tags\OnGroundTag;
use pocketmine\domain\ecs\System;
use pocketmine\domain\ecs\World;
use pocketmine\domain\resource\SpatialIndex;

final class PhysicsSystem implements System {
    public function run(World $world, float $deltaTime): void {
        $spatialIndex = $world->getResourceRegistry()->get(SpatialIndex::class);
        if (!$spatialIndex) {
            return;
        }

        $query = $world->query()
            ->with(PositionComponent::class, VelocityComponent::class)
            ->build();

        foreach ($query as $entity) {
            $position = $entity->get(PositionComponent::class);
            $velocity = $entity->get(VelocityComponent::class);

            // Apply gravity
            $velocity->y -= 0.08 * $deltaTime; // Gravity constant

            // Simple ground collision
            if ($position->y <= 0) {
                $position->y = 0;
                $velocity->y = 0;
                $entity->set(OnGroundTag::class, new OnGroundTag());
            } else {
                $entity->remove(OnGroundTag::class);
            }

            // TODO: Broad-phase collision using SpatialIndex
        }
    }
}
<?php

declare(strict_types=1);

namespace pocketmine\domain\system;

use pocketmine\domain\component\PositionComponent;
use pocketmine\domain\component\VelocityComponent;
use pocketmine\domain\component\tags\OnGroundTag;
use pocketmine\domain\ecs\Archetype;
use pocketmine\domain\ecs\ParallelSystem;
use pocketmine\domain\ecs\World;
use pocketmine\domain\resource\SpatialIndex;

final class PhysicsSystem implements ParallelSystem {
    public function run(World $world, float $deltaTime): void {
        // Not used - ParallelSystem uses runParallel
    }

    public function runParallel(Archetype $archetype, float $deltaTime): void {
        $positions = $archetype->getComponentArray(PositionComponent::class);
        $velocities = $archetype->getComponentArray(VelocityComponent::class);

        if (empty($positions) || empty($velocities)) {
            return;
        }

        $count = min(count($positions), count($velocities));
        for ($i = 0; $i < $count; $i++) {
            $position = $positions[$i];
            $velocity = $velocities[$i];
            
            if ($position === null || $velocity === null) {
                continue;
            }

            // Apply gravity - write to pending velocity
            $newVelY = $velocity->y - 0.08 * $deltaTime;

            // Simple ground collision - check pending position
            $newY = $position->y + $velocity->y * $deltaTime;
            $newX = $position->x + $velocity->x * $deltaTime;
            $newZ = $position->z + $velocity->z * $deltaTime;

            if ($newY <= 0) {
                $newY = 0;
                $newVelY = 0;
                // OnGroundTag would be set by a separate system or here
            }

            // Write to pending components for parallel safety
            $position->setPending($newX, $newY, $newZ);
            $velocity->setPending($velocity->x, $newVelY, $velocity->z);
        }
    }

    public function getTargetArchetypes(World $world): iterable {
        $query = $world->query()
            ->with(\pocketmine\domain\component\PositionComponent::class, \pocketmine\domain\component\VelocityComponent::class)
            ->build();

        $registry = $world->getComponentRegistry();
        return $query->archetypes($registry);
    }
}
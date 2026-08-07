<?php

declare(strict_types=1);

namespace pocketmine\domain\system;

use pocketmine\domain\component\PositionComponent;
use pocketmine\domain\component\VelocityComponent;
use pocketmine\domain\component\tags\InvisibleTag;
use pocketmine\domain\ecs\Archetype;
use pocketmine\domain\ecs\ParallelSystem;
use pocketmine\domain\ecs\World;

final class MovementSystem implements ParallelSystem {
    public function run(World $world, float $deltaTime): void {
        // Not used - ParallelSystem uses runParallel
    }

    public function runParallel(Archetype $archetype, float $deltaTime): void {
        $positions = $archetype->getComponentArray(PositionComponent::class);
        $velocities = $archetype->getComponentArray(VelocityComponent::class);

        if (empty($positions) || empty($velocities)) {
            return;
        }

        // Iterate over flat arrays using index
        $count = min(count($positions), count($velocities));
        for ($i = 0; $i < $count; $i++) {
            $position = $positions[$i];
            $velocity = $velocities[$i];
            
            if ($position === null || $velocity === null) {
                continue;
            }

            // Write to pending (double-buffered) position for parallel safety
            $newX = $position->x + $velocity->x * $deltaTime;
            $newY = $position->y + $velocity->y * $deltaTime;
            $newZ = $position->z + $velocity->z * $deltaTime;
            $position->setPending($newX, $newY, $newZ, $position->yaw, $position->pitch);
        }
    }

    public function getTargetArchetypes(World $world): iterable {
        $query = $world->query()
            ->with(PositionComponent::class, VelocityComponent::class)
            ->without(InvisibleTag::class)
            ->build();

        $registry = $world->getComponentRegistry();
        return $query->archetypes($registry);
    }
}
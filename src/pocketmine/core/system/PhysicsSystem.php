<?php

declare(strict_types=1);

namespace pocketmine\core\system;

use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\component\tags\OnGroundTag;
use pocketmine\core\ecs\Archetype;
use pocketmine\core\ecs\ParallelSystem;
use pocketmine\core\ecs\World;
use pocketmine\core\resource\SpatialIndex;

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

            // Apply gravity - write to pending velocity. MC parity: 0.08
            // blocks/tick^2 = 1.6 blocks/s^2 at 20 TPS (the old 0.08/s^2 made
            // drops hover in the air for many seconds before landing).
            $newVelY = $velocity->y - 1.6 * $deltaTime;
            // Terminal velocity ~3.92 blocks/tick (78.4 blocks/s), like MC.
            if ($newVelY < -78.4) {
                $newVelY = -78.4;
            }

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
        // Players are excluded: their position (including Y) is
        // client-authoritative and the client owns their gravity - server
        // gravity would sink them through the terrain between movement
        // packets (they are also skipped by BlockCollisionSystem).
        $query = $world->query()
            ->with(\pocketmine\core\component\PositionComponent::class, \pocketmine\core\component\VelocityComponent::class)
            ->without(\pocketmine\core\component\tags\PlayerTag::class)
            ->build();

        $registry = $world->getComponentRegistry();
        return $query->archetypes($registry);
    }
}
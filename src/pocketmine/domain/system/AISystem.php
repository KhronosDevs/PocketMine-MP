<?php

declare(strict_types=1);

namespace pocketmine\domain\system;

use pocketmine\domain\component\AIStateComponent;
use pocketmine\domain\component\PathComponent;
use pocketmine\domain\component\PositionComponent;
use pocketmine\domain\component\VelocityComponent;
use pocketmine\domain\ecs\System;
use pocketmine\domain\ecs\World;
use pocketmine\domain\resource\SpatialIndex;

final class AISystem implements System {
    public function run(World $world, float $deltaTime): void {
        $spatialIndex = $world->getResourceRegistry()->get(SpatialIndex::class);

        $query = $world->query()
            ->with(AIStateComponent::class, PositionComponent::class, VelocityComponent::class)
            ->build();

        foreach ($query as $entity) {
            $ai = $entity->get(AIStateComponent::class);
            $position = $entity->get(PositionComponent::class);
            $velocity = $entity->get(VelocityComponent::class);

            if (!$ai->canNavigate) {
                continue;
            }

            switch ($ai->state) {
                case 0: // Idle
                    $this->handleIdle($entity, $ai, $position);
                    break;
                case 1: // Wandering
                    $this->handleWandering($entity, $ai, $position, $velocity);
                    break;
                case 2: // Pathfinding to position
                    $this->handlePathfinding($entity, $ai, $position, $velocity);
                    break;
                case 3: // Attacking/following entity
                    $this->handleAttacking($entity, $ai, $position, $velocity, $world);
                    break;
                case 4: // Fleeing
                    $this->handleFleeing($entity, $ai, $position, $velocity);
                    break;
            }
        }
    }

    private function handleIdle(Entity $entity, AIStateComponent $ai, PositionComponent $position): void {
        $ai->updateCounter++;
        if ($ai->updateCounter >= 200) { // ~10 seconds
            $ai->updateCounter = 0;
            // Random chance to start wandering
            if (mt_rand(1, 10) <= 3) {
                $ai->state = 1;
                $angle = mt_rand(0, 360);
                $dist = mt_rand(5, 15);
                $ai->targetX = $position->x + sin(deg2rad($angle)) * $dist;
                $ai->targetZ = $position->z + cos(deg2rad($angle)) * $dist;
                $ai->targetY = $position->y;
            }
        }
    }

    private function handleWandering(Entity $entity, AIStateComponent $ai, PositionComponent $position, VelocityComponent $velocity): void {
        $dx = $ai->targetX - $position->x;
        $dz = $ai->targetZ - $position->z;
        $distSq = $dx * $dx + $dz * $dz;

        if ($distSq < 4.0) { // Close enough
            $ai->state = 0;
            return;
        }

        // Simple movement toward target
        $dist = sqrt($distSq);
        $speed = 0.2 * $ai->speedModifier;
        $velocity->x = ($dx / $dist) * $speed;
        $velocity->z = ($dz / $dist) * $speed;
    }

    private function handlePathfinding(Entity $entity, AIStateComponent $ai, PositionComponent $position, VelocityComponent $velocity): void {
        if ($ai->hasPath()) {
            $next = $ai->getNextPathPoint();
            if ($next) {
                $dx = $next['x'] - $position->x;
                $dy = $next['y'] - $position->y;
                $dz = $next['z'] - $position->z;
                $dist = sqrt($dx * $dx + $dy * $dy + $dz * $dz);

                if ($dist < 0.5) {
                    // Close enough, advance to next node
                    return;
                }

                $speed = 0.3 * $ai->speedModifier;
                $velocity->x = ($dx / $dist) * $speed;
                $velocity->y = ($dy / $dist) * $speed * 0.5;
                $velocity->z = ($dz / $dist) * $speed;
            }
        } else {
            // Path complete or no path
            $ai->state = 0;
        }
    }

    private function handleAttacking(Entity $entity, AIStateComponent $ai, PositionComponent $position, VelocityComponent $velocity, World $world): void {
        if ($ai->targetEntity === null) {
            $ai->state = 0;
            return;
        }

        $target = $world->getEntity($ai->targetEntity);
        if (!$target) {
            $ai->clearTarget();
            return;
        }

        $targetPos = $target->get(PositionComponent::class);
        if (!$targetPos) {
            $ai->clearTarget();
            return;
        }

        $dx = $targetPos->x - $position->x;
        $dy = $targetPos->y - $position->y;
        $dz = $targetPos->z - $position->z;
        $distSq = $dx * $dx + $dy * $dy + $dz * $dz;
        $attackRangeSq = $ai->attackRange * $ai->attackRange;

        if ($distSq <= $attackRangeSq) {
            // In attack range - stop and attack
            $velocity->x = 0;
            $velocity->z = 0;
            // TODO: Trigger attack logic
        } else {
            // Move toward target
            $dist = sqrt($distSq);
            $speed = 0.35 * $ai->speedModifier;
            $velocity->x = ($dx / $dist) * $speed;
            $velocity->z = ($dz / $dist) * $speed;
        }
    }

    private function handleFleeing(Entity $entity, AIStateComponent $ai, PositionComponent $position, VelocityComponent $velocity): void {
        // Move away from target
        if ($ai->targetEntity !== null) {
            $target = $world->getEntity($ai->targetEntity);
            if ($target) {
                $targetPos = $target->get(PositionComponent::class);
                if ($targetPos) {
                    $dx = $position->x - $targetPos->x;
                    $dz = $position->z - $targetPos->z;
                    $dist = sqrt($dx * $dx + $dz * $dz);

                    if ($dist > 0) {
                        $speed = 0.4 * $ai->speedModifier;
                        $velocity->x = ($dx / $dist) * $speed;
                        $velocity->z = ($dz / $dist) * $speed;
                    }
                }
            }
        }
    }
}
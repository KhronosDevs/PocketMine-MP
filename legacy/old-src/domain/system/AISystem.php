<?php

declare(strict_types=1);

namespace pocketmine\domain\system;

use pocketmine\domain\component\AIStateComponent;
use pocketmine\domain\component\PathComponent;
use pocketmine\domain\component\PositionComponent;
use pocketmine\domain\component\VelocityComponent;
use pocketmine\domain\ecs\Archetype;
use pocketmine\domain\ecs\ParallelSystem;
use pocketmine\domain\ecs\World;

final class AISystem implements ParallelSystem {
    public function run(World $world, float $deltaTime): void {
        // Not used - ParallelSystem uses runParallel
    }

    public function runParallel(Archetype $archetype, float $deltaTime): void {
        $aiStates = $archetype->getComponentArray(AIStateComponent::class);
        $positions = $archetype->getComponentArray(PositionComponent::class);
        $velocities = $archetype->getComponentArray(VelocityComponent::class);

        if (empty($aiStates) || empty($positions) || empty($velocities)) {
            return;
        }

        $count = min(count($aiStates), count($positions), count($velocities));
        for ($i = 0; $i < $count; $i++) {
            $ai = $aiStates[$i];
            $position = $positions[$i];
            $velocity = $velocities[$i];

            if (!$ai || !$position || !$velocity || !$ai->canNavigate) {
                continue;
            }

            // Use pending components for parallel writes
            switch ($ai->state) {
                case 0: // Idle
                    $this->handleIdle($ai, $position);
                    break;
                case 1: // Wandering
                    $this->handleWandering($ai, $position, $velocity);
                    break;
                case 2: // Pathfinding to position
                    $this->handlePathfinding($ai, $position, $velocity);
                    break;
                case 3: // Attacking/following entity
                    $this->handleAttacking($archetype, $ai, $position, $velocity);
                    break;
                case 4: // Fleeing
                    $this->handleFleeing($archetype, $ai, $position, $velocity);
                    break;
            }
        }
    }

    private function handleIdle(AIStateComponent $ai, PositionComponent $position): void {
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

    private function handleWandering(AIStateComponent $ai, PositionComponent $position, VelocityComponent $velocity): void {
        $dx = $ai->targetX - $position->x;
        $dz = $ai->targetZ - $position->z;
        $distSq = $dx * $dx + $dz * $dz;

        if ($distSq < 4.0) { // Close enough
            $ai->state = 0;
            return;
        }

        // Simple movement toward target - write to pending velocity
        $dist = sqrt($distSq);
        $speed = 0.2 * $ai->speedModifier;
        $velocity->setPending(($dx / $dist) * $speed, $velocity->y, ($dz / $dist) * $speed);
    }

    private function handlePathfinding(AIStateComponent $ai, PositionComponent $position, VelocityComponent $velocity): void {
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
                $velocity->setPending(($dx / $dist) * $speed, ($dy / $dist) * $speed * 0.5, ($dz / $dist) * $speed);
            }
        } else {
            // Path complete or no path
            $ai->state = 0;
        }
    }

    private function handleAttacking(Archetype $archetype, AIStateComponent $ai, PositionComponent $position, VelocityComponent $velocity): void {
        if ($ai->targetEntity === null) {
            $ai->state = 0;
            return;
        }

        // Target entity lookup would need to be done carefully in parallel
        // For now, simplified - just move toward target position
        if ($ai->targetEntity !== null) {
            $targetPos = $archetype->getComponentArray(PositionComponent::class)[$ai->targetEntity] ?? null;
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
                $velocity->setPending(0, $velocity->y, 0);
                // TODO: Trigger attack logic
            } else {
                // Move toward target
                $dist = sqrt($distSq);
                $speed = 0.35 * $ai->speedModifier;
                $velocity->setPending(($dx / $dist) * $speed, $velocity->y, ($dz / $dist) * $speed);
            }
        }
    }

    private function handleFleeing(Archetype $archetype, AIStateComponent $ai, PositionComponent $position, VelocityComponent $velocity): void {
        // Move away from target
        if ($ai->targetEntity !== null) {
            $targetPos = $archetype->getComponentArray(PositionComponent::class)[$ai->targetEntity] ?? null;
            if ($targetPos) {
                $dx = $position->x - $targetPos->x;
                $dz = $position->z - $targetPos->z;
                $dist = sqrt($dx * $dx + $dz * $dz);

                if ($dist > 0) {
                    $speed = 0.4 * $ai->speedModifier;
                    $velocity->setPending(($dx / $dist) * $speed, $velocity->y, ($dz / $dist) * $speed);
                }
            }
        }
    }

    public function getTargetArchetypes(World $world): iterable {
        $query = $world->query()
            ->with(\pocketmine\domain\component\AIStateComponent::class, \pocketmine\domain\component\PositionComponent::class, \pocketmine\domain\component\VelocityComponent::class)
            ->build();

        $registry = $world->getComponentRegistry();
        return $query->archetypes($registry);
    }
}
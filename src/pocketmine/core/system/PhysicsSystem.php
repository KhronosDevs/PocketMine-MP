<?php

declare(strict_types=1);

namespace pocketmine\core\system;

use pocketmine\adapter\driven\threading\ArchetypeSnapshot;
use pocketmine\adapter\driven\threading\ParallelResult;
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
            }

            // Air drag (old-src Item drag = 0.02, friction = 0.98/tick).
            $drag = 0.98;
            $newVelX = $velocity->x * $drag;
            $newVelZ = $velocity->z * $drag;

            // When all axes are nearly still, snap to zero so items
            // stop on the ground instead of sliding forever.
            if (abs($newVelX) < 0.05 && abs($newVelZ) < 0.05
                && abs($newVelY) < 0.05) {
                $newVelX = 0.0;
                $newVelY = 0.0;
                $newVelZ = 0.0;
            }

            // Write to pending components for parallel safety
            $position->setPending($newX, $newY, $newZ);
            $velocity->setPending($newVelX, $newVelY, $newVelZ);
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

    /**
     * Snapshot archetype data for cross-thread dispatch.
     */
    public static function snapshotArchetype(Archetype $archetype, float $deltaTime): ArchetypeSnapshot {
        $positions = $archetype->getComponentArray(PositionComponent::class);
        $velocities = $archetype->getComponentArray(VelocityComponent::class);

        $px = []; $py = []; $pz = [];
        $vx = []; $vy = []; $vz = [];

        $count = min(count($positions), count($velocities));
        $n = 0;
        for ($i = 0; $i < $count; $i++) {
            $p = $positions[$i];
            $v = $velocities[$i];
            if ($p === null || $v === null) {
                continue;
            }
            $px[] = $p->x;
            $py[] = $p->y;
            $pz[] = $p->z;
            $vx[] = $v->x;
            $vy[] = $v->y;
            $vz[] = $v->z;
            $n++;
        }

        return ArchetypeSnapshot::fromPayload([
            'positionsX' => $px,
            'positionsY' => $py,
            'positionsZ' => $pz,
            'velocitiesX' => $vx,
            'velocitiesY' => $vy,
            'velocitiesZ' => $vz,
        ], $n, $deltaTime);
    }

    /**
     * Apply worker results back to pending component buffers.
     */
    public static function applyResult(Archetype $archetype, ParallelResult $result): void {
        if ($result->count <= 0) {
            return;
        }
        $payload = $result->getPayload();
        $positions = $archetype->getComponentArray(PositionComponent::class);
        $velocities = $archetype->getComponentArray(VelocityComponent::class);
        $count = min(count($positions), count($velocities), $result->count);

        for ($i = 0; $i < $count; $i++) {
            $p = $positions[$i];
            $v = $velocities[$i];
            if ($p === null || $v === null) {
                continue;
            }
            $p->setPending(
                $payload['pendingPositionsX'][$i] ?? $p->x,
                $payload['pendingPositionsY'][$i] ?? $p->y,
                $payload['pendingPositionsZ'][$i] ?? $p->z,
            );
            if ($v !== null) {
                $v->setPending(
                    $payload['pendingVelocitiesX'][$i] ?? $v->x,
                    $payload['pendingVelocitiesY'][$i] ?? $v->y,
                    $payload['pendingVelocitiesZ'][$i] ?? $v->z,
                );
            }
        }
    }

    /**
     * Compute physics (gravity + movement) on a worker thread from snapshot data.
     * Pure function: reads flat arrays, writes flat arrays. No ECS objects touched.
     */
    public static function computeOnSnapshot(ArchetypeSnapshot $snap, ParallelResult $out): void {
        $payload = $snap->getPayload();
        $count = $snap->count;
        if ($count <= 0) {
            $out->count = 0;
            return;
        }

        $dt = $snap->deltaTime;
        $px = $payload['positionsX'];
        $py = $payload['positionsY'];
        $pz = $payload['positionsZ'];
        $vx = $payload['velocitiesX'];
        $vy = $payload['velocitiesY'];
        $vz = $payload['velocitiesZ'];

        $outPX = []; $outPY = []; $outPZ = [];
        $outVX = []; $outVY = []; $outVZ = [];

        for ($i = 0; $i < $count; $i++) {
            // Gravity: MC parity 0.08 blocks/tick^2 = 1.6 blocks/s^2 at 20 TPS
            $newVelY = $vy[$i] - 1.6 * $dt;
            if ($newVelY < -78.4) {
                $newVelY = -78.4;
            }

            $newX = $px[$i] + $vx[$i] * $dt;
            $newY = $py[$i] + $vy[$i] * $dt;
            $newZ = $pz[$i] + $vz[$i] * $dt;

            if ($newY <= 0) {
                $newY = 0;
                $newVelY = 0;
            }

            // Air drag + snap-to-zero when nearly still.
            $drag = 0.98;
            $newVelX = $vx[$i] * $drag;
            $newVelZ = $vz[$i] * $drag;
            if (abs($newVelX) < 0.05 && abs($newVelZ) < 0.05
                && abs($newVelY) < 0.05) {
                $newVelX = 0.0;
                $newVelY = 0.0;
                $newVelZ = 0.0;
            }

            $outPX[] = $newX;
            $outPY[] = $newY;
            $outPZ[] = $newZ;
            $outVX[] = $newVelX;
            $outVY[] = $newVelY;
            $outVZ[] = $newVelZ;
        }

        $out->setPayload([
            'pendingPositionsX' => $outPX,
            'pendingPositionsY' => $outPY,
            'pendingPositionsZ' => $outPZ,
            'pendingVelocitiesX' => $outVX,
            'pendingVelocitiesY' => $outVY,
            'pendingVelocitiesZ' => $outVZ,
        ], $count);
    }

    /**
     * Synchronous fallback for small archetypes (< 16 entities).
     */
    public static function applySnapshotSync(Archetype $archetype, ArchetypeSnapshot $snap): void {
        if ($snap->count <= 0) {
            return;
        }
        $result = new ParallelResult();
        self::computeOnSnapshot($snap, $result);
        self::applyResult($archetype, $result);
    }
}

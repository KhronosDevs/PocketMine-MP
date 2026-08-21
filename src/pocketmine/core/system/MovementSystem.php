<?php

declare(strict_types=1);

namespace pocketmine\core\system;

use pocketmine\adapter\driven\threading\ArchetypeSnapshot;
use pocketmine\adapter\driven\threading\ParallelResult;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\component\tags\InvisibleTag;
use pocketmine\core\ecs\Archetype;
use pocketmine\core\ecs\ParallelSystem;
use pocketmine\core\ecs\World;

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
        // Players are excluded, matching PhysicsSystem: their position is
        // client-authoritative. Including them here meant any residual
        // VelocityComponent (e.g. knockback that nothing resets) dragged the
        // server-side copy of the player every tick forever - other viewers
        // saw the victim drift away while their own client never moved.
        $query = $world->query()
            ->with(PositionComponent::class, VelocityComponent::class)
            ->without(InvisibleTag::class)
            ->without(\pocketmine\core\component\tags\PlayerTag::class)
            ->build();

        $registry = $world->getComponentRegistry();
        return $query->archetypes($registry);
    }

    /**
     * Snapshot an archetype's component data into a flat JSON payload for
     * cross-thread dispatch. Only reads scalars — no object references
     * cross the thread boundary.
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
     * Apply worker results back to the main thread's pending component buffers.
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
            if ($p === null) {
                continue;
            }
            $p->setPending(
                $payload['pendingPositionsX'][$i] ?? $p->x,
                $payload['pendingPositionsY'][$i] ?? $p->y,
                $payload['pendingPositionsZ'][$i] ?? $p->z,
                $p->yaw,
                $p->pitch,
            );
        }
    }

    /**
     * Compute movement on a worker thread from snapshot data.
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

        $outX = []; $outY = []; $outZ = [];
        for ($i = 0; $i < $count; $i++) {
            $outX[] = $px[$i] + $vx[$i] * $dt;
            $outY[] = $py[$i] + $vy[$i] * $dt;
            $outZ[] = $pz[$i] + $vz[$i] * $dt;
        }

        $out->setPayload([
            'pendingPositionsX' => $outX,
            'pendingPositionsY' => $outY,
            'pendingPositionsZ' => $outZ,
            'pendingVelocitiesX' => [],
            'pendingVelocitiesY' => [],
            'pendingVelocitiesZ' => [],
        ], $count);
    }

    /**
     * Synchronous fallback for small archetypes (< 16 entities) where the
     * pool dispatch overhead exceeds the computation cost.
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

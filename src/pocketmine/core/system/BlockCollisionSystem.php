<?php

declare(strict_types=1);

namespace pocketmine\core\system;

use pocketmine\core\component\CollisionComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\component\tags\PlayerTag;
use pocketmine\core\ecs\System;
use pocketmine\core\ecs\World;
use pocketmine\core\resource\BlockRegistry;
use pocketmine\core\resource\ChunkStore;

/**
 * Server-side block collision for non-player entities (mobs, dropped items).
 *
 * The scheduler runs this AFTER the parallel movement/physics systems have
 * written PENDING positions and velocities but BEFORE applyPendingComponents()
 * commits them, so a blocked entity never commits an in-wall position. Each
 * axis is moved and resolved independently (axis-separated sweep): an entity
 * slides along a wall instead of sticking to it, the pending velocity
 * component along a blocked axis is zeroed so falling entities land on the
 * first solid block below and stop, and the whole pass costs O(swept voxels)
 * per entity.
 *
 * Players are excluded - their position is client-authoritative (the client
 * enforces its own collision locally) and yanking them back would fight the
 * client's interpolation. Vehicles are excluded too - VehicleSystem owns
 * their vertical motion (boats float on water, minecarts ride rails); a
 * generic ground clamp would pull a minecart off its rail onto the floor
 * below it.
 */
final class BlockCollisionSystem implements System {
    /** Nudge so a resolved box sits just outside the blocking block's face. */
    private const EPSILON = 0.001;

    public function run(World $world, float $deltaTime): void {
        $store = $world->getResourceRegistry()->get(ChunkStore::class);
        $registry = $world->getResourceRegistry()->get(BlockRegistry::class);
        if (!$store instanceof ChunkStore || !$registry instanceof BlockRegistry) {
            return;
        }

        // 256-entry id => 1/0 solid flags, built once per tick (registry
        // memoizes it after the first call). Lets the voxel sweeps below
        // index raw chunk bytes without an isSolid() function call per voxel.
        $solidFlags = $registry->getSolidFlags();

        $query = $world->query()
            ->with(PositionComponent::class, VelocityComponent::class, CollisionComponent::class)
            ->without(PlayerTag::class, \pocketmine\core\constants\EntityTags::VEHICLE)
            ->build();

        foreach ($query as $entity) {
            $pos = $entity->get(PositionComponent::class);
            $vel = $entity->get(VelocityComponent::class);
            $col = $entity->get(CollisionComponent::class);
            if ($pos === null || $vel === null || $col === null
                || !$col->canCollide || !$col->collidesWithBlocks) {
                continue;
            }
            // A mounted rider is positioned by its vehicle (VehicleSystem
            // owns both entities' motion); clamping it here would fight the
            // seat sync and drag the rider into the rail/ground. Metadata is
            // optional for non-riders (it is not part of the archetype query).
            $meta = $entity->get(\pocketmine\core\component\MetadataComponent::class);
            if ($meta !== null && ((int)($meta->get(\pocketmine\core\constants\MetadataKeys::RIDING_VEHICLE_ID) ?? 0)) > 0) {
                continue;
            }

            // Base the sweep on the COMMITTED position (the parallel
            // movement/physics systems already integrated the pending
            // position once; starting from the pending value here would
            // integrate velocity a second time and move entities at 2x
            // speed). Velocity uses the pending (gravity-adjusted) value so
            // falling entities sweep with gravity applied.
            $x = $pos->x;
            $y = $pos->y;
            $z = $pos->z;
            $vx = $vel->pending?->x ?? $vel->x;
            $vy = $vel->pending?->y ?? $vel->y;
            $vz = $vel->pending?->z ?? $vel->z;

            // X, then Y, then Z: each axis is resolved against the position
            // settled by the previous one, so a blocked axis does not stop
            // the other two (sliding along walls, walking up to ledges).
            // A zero-displacement axis is skipped entirely: a stationary
            // entity (idle mob, settled item) has nothing to resolve - the
            // probe would only re-check the same footprint it occupied last
            // tick. Gravity keeps the Y sweep live for every entity; X/Z are
            // swept only when actually moving on that axis.
            if ($vx !== 0.0) {
                $tx = $x + $vx * $deltaTime;
                if ($this->collidesAt($store, $solidFlags, $col, $tx, $y, $z)) {
                    [$x, $vx] = $this->clampAxis($store, $solidFlags, $col, $x, $y, $z, $tx, $vx, 'x');
                } else {
                    $x = $tx;
                }
            }

            $ty = $y + $vy * $deltaTime;
            if ($this->collidesAt($store, $solidFlags, $col, $x, $ty, $z)) {
                [$y, $vy] = $this->clampAxis($store, $solidFlags, $col, $x, $y, $z, $ty, $vy, 'y');
                // Item entities stop completely when they hit the ground
                // (no sliding). Match vanilla MCPE: drops land and stay put.
                if ($vy === 0.0 && $entity->has(\pocketmine\core\constants\EntityTags::ITEM)) {
                    $vx = 0.0;
                    $vz = 0.0;
                }
            } else {
                $y = $ty;
            }

            if ($vz !== 0.0) {
                $tz = $z + $vz * $deltaTime;
                if ($this->collidesAt($store, $solidFlags, $col, $x, $y, $tz)) {
                    [$z, $vz] = $this->clampAxis($store, $solidFlags, $col, $x, $y, $z, $tz, $vz, 'z');
                } else {
                    $z = $tz;
                }
            }

            $pos->setPending(
                $x, $y, $z,
                $pos->pending?->yaw ?? $pos->yaw,
                $pos->pending?->pitch ?? $pos->pitch,
            );
            $vel->setPending($vx, $vy, $vz);
        }
    }

    /**
     * Whether the entity's AABB at (x, y, z) overlaps any solid block.
     */
    private function collidesAt(
        ChunkStore $store,
        array $solidFlags,
        CollisionComponent $col,
        float $x,
        float $y,
        float $z,
    ): bool {
        $hw = $col->getHalfWidth();
        $minX = (int)floor($x - $hw + self::EPSILON);
        $maxX = (int)floor($x + $hw - self::EPSILON);
        $minY = (int)floor($y + self::EPSILON);
        $maxY = (int)floor($y + $col->height - self::EPSILON);
        $minZ = (int)floor($z - $hw + self::EPSILON);
        $maxZ = (int)floor($z + $hw - self::EPSILON);
        return $store->probeSolidFootprint($minX, $maxX, $minY, $maxY, $minZ, $maxZ, $solidFlags);
    }

    /**
     * Clamp the position on a blocked axis to the nearest solid face in the
     * direction of travel and zero the axis velocity.
     *
     * @return array{0: float, 1: float} [clamped position, 0.0 velocity]
     */
    private function clampAxis(
        ChunkStore $store,
        array $solidFlags,
        CollisionComponent $col,
        float $x,
        float $y,
        float $z,
        float $t,
        float $v,
        string $axis,
    ): array {
        $hw = $col->getHalfWidth();
        if ($axis === 'x') {
            if ($v > 0) {
                $start = (int)floor($x + $hw + self::EPSILON);
                $limit = (int)floor($t + $hw + self::EPSILON);
                for ($i = $start; $i <= $limit; $i++) {
                    if ($this->slabCollides($store, $solidFlags, $col, 'x', $i, $x, $y, $z)) {
                        return [$i - $hw - self::EPSILON, 0.0];
                    }
                }
            } else {
                $start = (int)floor($x - $hw - self::EPSILON);
                $limit = (int)floor($t - $hw - self::EPSILON);
                for ($i = $start; $i >= $limit; $i--) {
                    if ($this->slabCollides($store, $solidFlags, $col, 'x', $i, $x, $y, $z)) {
                        return [$i + $hw + self::EPSILON, 0.0];
                    }
                }
            }
            return [$t, $v];
        }

        if ($axis === 'y') {
            if ($v > 0) { // ceiling: clamp so the top of the box sits below the block
                $start = (int)floor($y + $col->height + self::EPSILON);
                $limit = (int)floor($t + $col->height + self::EPSILON);
                for ($i = $start; $i <= $limit; $i++) {
                    if ($this->slabCollides($store, $solidFlags, $col, 'y', $i, $x, $y, $z)) {
                        return [$i - $col->height - self::EPSILON, 0.0];
                    }
                }
            } else { // floor: land the box's feet on the block's top face
                $start = (int)floor($y - self::EPSILON);
                $limit = (int)floor($t - self::EPSILON);
                for ($i = $start; $i >= $limit; $i--) {
                    if ($i < 0) {
                        break;
                    }
                    if ($this->slabCollides($store, $solidFlags, $col, 'y', $i, $x, $y, $z)) {
                        return [$i + 1.0 + self::EPSILON, 0.0];
                    }
                }
            }
            return [$t, $v];
        }

        if ($v > 0) {
            $start = (int)floor($z + $hw + self::EPSILON);
            $limit = (int)floor($t + $hw + self::EPSILON);
            for ($i = $start; $i <= $limit; $i++) {
                if ($this->slabCollides($store, $solidFlags, $col, 'z', $i, $x, $y, $z)) {
                    return [$i - $hw - self::EPSILON, 0.0];
                }
            }
        } else {
            $start = (int)floor($z - $hw - self::EPSILON);
            $limit = (int)floor($t - $hw - self::EPSILON);
            for ($i = $start; $i >= $limit; $i--) {
                if ($this->slabCollides($store, $solidFlags, $col, 'z', $i, $x, $y, $z)) {
                    return [$i + $hw + self::EPSILON, 0.0];
                }
            }
        }
        return [$t, $v];
    }

    /**
     * Whether any solid block exists in the single voxel slab at index $i on
     * the given axis, over the box's footprint on the other two axes.
     */
    private function slabCollides(
        ChunkStore $store,
        array $solidFlags,
        CollisionComponent $col,
        string $axis,
        int $i,
        float $x,
        float $y,
        float $z,
    ): bool {
        $hw = $col->getHalfWidth();
        $minX = (int)floor($x - $hw + self::EPSILON);
        $maxX = (int)floor($x + $hw - self::EPSILON);
        $minY = (int)floor($y + self::EPSILON);
        $maxY = (int)floor($y + $col->height - self::EPSILON);
        $minZ = (int)floor($z - $hw + self::EPSILON);
        $maxZ = (int)floor($z + $hw - self::EPSILON);

        if ($axis === 'x') {
            return $store->probeSolidFootprint($i, $i, $minY, $maxY, $minZ, $maxZ, $solidFlags);
        }

        if ($axis === 'y') {
            return $store->probeSolidFootprint($minX, $maxX, $i, $i, $minZ, $maxZ, $solidFlags);
        }

        return $store->probeSolidFootprint($minX, $maxX, $minY, $maxY, $i, $i, $solidFlags);
    }
}

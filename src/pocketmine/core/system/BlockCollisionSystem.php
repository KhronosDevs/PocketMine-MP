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
 * client's interpolation.
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

        $query = $world->query()
            ->with(PositionComponent::class, VelocityComponent::class, CollisionComponent::class)
            ->without(PlayerTag::class)
            ->build();

        foreach ($query as $entity) {
            $pos = $entity->get(PositionComponent::class);
            $vel = $entity->get(VelocityComponent::class);
            $col = $entity->get(CollisionComponent::class);
            if ($pos === null || $vel === null || $col === null
                || !$col->canCollide || !$col->collidesWithBlocks) {
                continue;
            }

            // The pending values written by the parallel systems (fall back to
            // the committed state for entities no system touched this tick).
            $x = $pos->pending?->x ?? $pos->x;
            $y = $pos->pending?->y ?? $pos->y;
            $z = $pos->pending?->z ?? $pos->z;
            $vx = $vel->pending?->x ?? $vel->x;
            $vy = $vel->pending?->y ?? $vel->y;
            $vz = $vel->pending?->z ?? $vel->z;

            // X, then Y, then Z: each axis is resolved against the position
            // settled by the previous one, so a blocked axis does not stop
            // the other two (sliding along walls, walking up to ledges).
            $tx = $x + $vx * $deltaTime;
            if ($this->collidesAt($store, $registry, $col, $tx, $y, $z)) {
                [$x, $vx] = $this->clampAxis($store, $registry, $col, $x, $y, $z, $tx, $vx, 'x');
            } else {
                $x = $tx;
            }

            $ty = $y + $vy * $deltaTime;
            if ($this->collidesAt($store, $registry, $col, $x, $ty, $z)) {
                [$y, $vy] = $this->clampAxis($store, $registry, $col, $x, $y, $z, $ty, $vy, 'y');
            } else {
                $y = $ty;
            }

            $tz = $z + $vz * $deltaTime;
            if ($this->collidesAt($store, $registry, $col, $x, $y, $tz)) {
                [$z, $vz] = $this->clampAxis($store, $registry, $col, $x, $y, $z, $tz, $vz, 'z');
            } else {
                $z = $tz;
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
        BlockRegistry $registry,
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
        for ($bx = $minX; $bx <= $maxX; $bx++) {
            for ($by = $minY; $by <= $maxY; $by++) {
                if ($by < 0 || $by > 255) {
                    continue; // never collide with the void / ceiling guard
                }
                for ($bz = $minZ; $bz <= $maxZ; $bz++) {
                    if ($registry->isSolid($store->getBlock($bx, $by, $bz))) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Clamp the position on a blocked axis to the nearest solid face in the
     * direction of travel and zero the axis velocity.
     *
     * @return array{0: float, 1: float} [clamped position, 0.0 velocity]
     */
    private function clampAxis(
        ChunkStore $store,
        BlockRegistry $registry,
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
                    if ($this->slabCollides($store, $registry, $col, 'x', $i, $x, $y, $z)) {
                        return [$i - $hw - self::EPSILON, 0.0];
                    }
                }
            } else {
                $start = (int)floor($x - $hw - self::EPSILON);
                $limit = (int)floor($t - $hw - self::EPSILON);
                for ($i = $start; $i >= $limit; $i--) {
                    if ($this->slabCollides($store, $registry, $col, 'x', $i, $x, $y, $z)) {
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
                    if ($this->slabCollides($store, $registry, $col, 'y', $i, $x, $y, $z)) {
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
                    if ($this->slabCollides($store, $registry, $col, 'y', $i, $x, $y, $z)) {
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
                if ($this->slabCollides($store, $registry, $col, 'z', $i, $x, $y, $z)) {
                    return [$i - $hw - self::EPSILON, 0.0];
                }
            }
        } else {
            $start = (int)floor($z - $hw - self::EPSILON);
            $limit = (int)floor($t - $hw - self::EPSILON);
            for ($i = $start; $i >= $limit; $i--) {
                if ($this->slabCollides($store, $registry, $col, 'z', $i, $x, $y, $z)) {
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
        BlockRegistry $registry,
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
            for ($by = $minY; $by <= $maxY; $by++) {
                if ($by < 0 || $by > 255) {
                    continue;
                }
                for ($bz = $minZ; $bz <= $maxZ; $bz++) {
                    if ($registry->isSolid($store->getBlock($i, $by, $bz))) {
                        return true;
                    }
                }
            }
            return false;
        }

        if ($axis === 'y') {
            if ($i < 0 || $i > 255) {
                return false;
            }
            for ($bx = $minX; $bx <= $maxX; $bx++) {
                for ($bz = $minZ; $bz <= $maxZ; $bz++) {
                    if ($registry->isSolid($store->getBlock($bx, $i, $bz))) {
                        return true;
                    }
                }
            }
            return false;
        }

        for ($bx = $minX; $bx <= $maxX; $bx++) {
            for ($by = $minY; $by <= $maxY; $by++) {
                if ($by < 0 || $by > 255) {
                    continue;
                }
                if ($registry->isSolid($store->getBlock($bx, $by, $i))) {
                    return true;
                }
            }
        }
        return false;
    }
}

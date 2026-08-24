<?php

declare(strict_types=1);

namespace pocketmine\core\math;

/**
 * Immutable axis-aligned bounding box (AABB).
 *
 * Used for hitboxes, collision detection, raycasting, and region checks.
 * All methods return new instances (value object — no mutation).
 */
final class AxisAlignedBB {

    public function __construct(
        public readonly float $minX,
        public readonly float $minY,
        public readonly float $minZ,
        public readonly float $maxX,
        public readonly float $maxY,
        public readonly float $maxZ,
    ) {}

    /**
     * 1×1×1 bounding box for a single block at (x, y, z).
     */
    public static function ofBlock(int $x, int $y, int $z): self {
        return new self($x, $y, $z, $x + 1, $y + 1, $z + 1);
    }

    /**
     * Bounding box for an entity at position (x, y, z) with given width and height.
     * Width is the full width (not half); the box is centered on x/z.
     */
    public static function ofEntity(float $x, float $y, float $z, float $width, float $height): self {
        $hw = $width / 2.0;
        return new self($x - $hw, $y, $z - $hw, $x + $hw, $y + $height, $z + $hw);
    }

    /**
     * Expand the box outward by the given amounts on each axis.
     */
    public function grow(float $x, float $y, float $z): self {
        return new self(
            $this->minX - $x, $this->minY - $y, $this->minZ - $z,
            $this->maxX + $x, $this->maxY + $y, $this->maxZ + $z,
        );
    }

    /**
     * Shrink the box inward by the given amounts on each axis.
     */
    public function shrink(float $x, float $y, float $z): self {
        return new self(
            $this->minX + $x, $this->minY + $y, $this->minZ + $z,
            $this->maxX - $x, $this->maxY - $y, $this->maxZ - $z,
        );
    }

    /**
     * Move the box by the given offset.
     */
    public function offset(float $x, float $y, float $z): self {
        return new self(
            $this->minX + $x, $this->minY + $y, $this->minZ + $z,
            $this->maxX + $x, $this->maxY + $y, $this->maxZ + $z,
        );
    }

    /**
     * Expand the box to include the given coordinate.
     */
    public function addCoord(float $x, float $y, float $z): self {
        return new self(
            min($this->minX, $x), min($this->minY, $y), min($this->minZ, $z),
            max($this->maxX, $x), max($this->maxY, $y), max($this->maxZ, $z),
        );
    }

    /**
     * Does this box intersect with another?
     */
    public function intersectsWith(self $bb): bool {
        return $this->minX < $bb->maxX && $this->maxX > $bb->minX
            && $this->minY < $bb->maxY && $this->maxY > $bb->minY
            && $this->minZ < $bb->maxZ && $this->maxZ > $bb->minZ;
    }

    /**
     * Is the given point inside this box?
     */
    public function isVectorInside(float $x, float $y, float $z): bool {
        return $x >= $this->minX && $x <= $this->maxX
            && $y >= $this->minY && $y <= $this->maxY
            && $z >= $this->minZ && $z <= $this->maxZ;
    }

    /**
     * Distance from a point to the nearest face of this box.
     * Returns 0.0 if the point is inside.
     */
    public function distanceToPoint(float $x, float $y, float $z): float {
        $dx = max($this->minX - $x, 0.0, $x - $this->maxX);
        $dy = max($this->minY - $y, 0.0, $y - $this->maxY);
        $dz = max($this->minZ - $z, 0.0, $z - $this->maxZ);
        return sqrt($dx * $dx + $dy * $dy + $dz * $dz);
    }

    /**
     * Calculate the intercept of a ray segment with this box.
     *
     * @return RayTraceResult|null null if the ray misses
     */
    public function calculateIntercept(
        float $x1, float $y1, float $z1,
        float $x2, float $y2, float $z2,
    ): ?RayTraceResult {
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        $dz = $z2 - $z1;

        // Parametric t values for each axis entry/exit
        $tMin = -INF;
        $tMax = INF;
        $normalX = 0.0;
        $normalY = 0.0;
        $normalZ = 0.0;

        // X axis
        if (abs($dx) > 1e-10) {
            $invD = 1.0 / $dx;
            $t1 = ($this->minX - $x1) * $invD;
            $t2 = ($this->maxX - $x1) * $invD;
            if ($t1 < $t2) {
                if ($t1 > $tMin) { $tMin = $t1; $normalX = -1.0; $normalY = 0.0; $normalZ = 0.0; }
                $tMax = min($tMax, $t2);
            } else {
                if ($t2 > $tMin) { $tMin = $t2; $normalX = 1.0; $normalY = 0.0; $normalZ = 0.0; }
                $tMax = min($tMax, $t1);
            }
        } else {
            if ($x1 < $this->minX || $x1 > $this->maxX) {
                return null;
            }
        }

        // Y axis
        if (abs($dy) > 1e-10) {
            $invD = 1.0 / $dy;
            $t1 = ($this->minY - $y1) * $invD;
            $t2 = ($this->maxY - $y1) * $invD;
            if ($t1 < $t2) {
                if ($t1 > $tMin) { $tMin = $t1; $normalX = 0.0; $normalY = -1.0; $normalZ = 0.0; }
                $tMax = min($tMax, $t2);
            } else {
                if ($t2 > $tMin) { $tMin = $t2; $normalX = 0.0; $normalY = 1.0; $normalZ = 0.0; }
                $tMax = min($tMax, $t1);
            }
        } else {
            if ($y1 < $this->minY || $y1 > $this->maxY) {
                return null;
            }
        }

        // Z axis
        if (abs($dz) > 1e-10) {
            $invD = 1.0 / $dz;
            $t1 = ($this->minZ - $z1) * $invD;
            $t2 = ($this->maxZ - $z1) * $invD;
            if ($t1 < $t2) {
                if ($t1 > $tMin) { $tMin = $t1; $normalX = 0.0; $normalY = 0.0; $normalZ = -1.0; }
                $tMax = min($tMax, $t2);
            } else {
                if ($t2 > $tMin) { $tMin = $t2; $normalX = 0.0; $normalY = 0.0; $normalZ = 1.0; }
                $tMax = min($tMax, $t1);
            }
        } else {
            if ($z1 < $this->minZ || $z1 > $this->maxZ) {
                return null;
            }
        }

        if ($tMin > $tMax || $tMin < 0.0) {
            return null;
        }

        // Intersection point
        $hitX = $x1 + $dx * $tMin;
        $hitY = $y1 + $dy * $tMin;
        $hitZ = $z1 + $dz * $tMin;

        // Determine which face was hit
        $face = self::faceFromNormal($normalX, $normalY, $normalZ);

        return new RayTraceResult($hitX, $hitY, $hitZ, $face, $tMin);
    }

    private static function faceFromNormal(float $nx, float $ny, float $nz): int {
        if ($ny > 0) return RayTraceResult::FACE_UP;
        if ($ny < 0) return RayTraceResult::FACE_DOWN;
        if ($nx > 0) return RayTraceResult::FACE_EAST;
        if ($nx < 0) return RayTraceResult::FACE_WEST;
        if ($nz > 0) return RayTraceResult::FACE_SOUTH;
        if ($nz < 0) return RayTraceResult::FACE_NORTH;
        return RayTraceResult::FACE_NONE;
    }

    public function __toString(): string {
        return "AxisAlignedBB({$this->minX}, {$this->minY}, {$this->minZ} -> {$this->maxX}, {$this->maxY}, {$this->maxZ})";
    }
}

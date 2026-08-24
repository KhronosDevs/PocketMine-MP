<?php

declare(strict_types=1);

namespace pocketmine\core\math;

/**
 * Result of a ray-AABB intersection test (AxisAlignedBB::calculateIntercept).
 *
 * Carries the hit point, the face that was struck, and the parametric
 * distance along the ray.
 */
final class RayTraceResult {

    public const FACE_NONE   = -1;
    public const FACE_DOWN   = 0;  // -Y
    public const FACE_UP     = 1;  // +Y
    public const FACE_NORTH  = 2;  // -Z
    public const FACE_SOUTH  = 3;  // +Z
    public const FACE_WEST   = 4;  // -X
    public const FACE_EAST   = 5;  // +X

    public function __construct(
        public readonly float $x,
        public readonly float $y,
        public readonly float $z,
        public readonly int $face,
        public readonly float $distance,
    ) {}

    public function getFaceName(): string {
        return match ($this->face) {
            self::FACE_DOWN  => 'down',
            self::FACE_UP    => 'up',
            self::FACE_NORTH => 'north',
            self::FACE_SOUTH => 'south',
            self::FACE_WEST  => 'west',
            self::FACE_EAST  => 'east',
            default          => 'none',
        };
    }

    public function __toString(): string {
        return "RayTraceResult({$this->x}, {$this->y}, {$this->z} face={$this->getFaceName()} dist={$this->distance})";
    }
}

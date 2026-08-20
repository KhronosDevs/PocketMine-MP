<?php

declare(strict_types=1);

namespace pocketmine\domain\component;

use pocketmine\domain\ecs\Component;

#[Component]
final class PositionComponent {
    public function __construct(
        public float $x = 0.0,
        public float $y = 0.0,
        public float $z = 0.0,
        public float $yaw = 0.0,
        public float $pitch = 0.0,
    ) {}

    // Double-buffering for parallel writes
    public ?PositionComponent $pending = null;

    public function applyPending(): void {
        if ($this->pending !== null) {
            $this->x = $this->pending->x;
            $this->y = $this->pending->y;
            $this->z = $this->pending->z;
            $this->yaw = $this->pending->yaw;
            $this->pitch = $this->pending->pitch;
            $this->pending = null;
        }
    }

    public function setPending(float $x, float $y, float $z, float $yaw = 0.0, float $pitch = 0.0): void {
        if ($this->pending === null) {
            $this->pending = new PositionComponent();
        }
        $this->pending->x = $x;
        $this->pending->y = $y;
        $this->pending->z = $z;
        $this->pending->yaw = $yaw;
        $this->pending->pitch = $pitch;
    }
}
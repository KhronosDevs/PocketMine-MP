<?php

declare(strict_types=1);

namespace pocketmine\domain\component;

use pocketmine\domain\ecs\Component;

#[Component]
final class VelocityComponent {
    public function __construct(
        public float $x = 0.0,
        public float $y = 0.0,
        public float $z = 0.0,
    ) {}

    // Double-buffering for parallel writes
    public ?VelocityComponent $pending = null;

    public function applyPending(): void {
        if ($this->pending !== null) {
            $this->x = $this->pending->x;
            $this->y = $this->pending->y;
            $this->z = $this->pending->z;
            $this->pending = null;
        }
    }

    public function setPending(float $x, float $y, float $z): void {
        if ($this->pending === null) {
            $this->pending = new VelocityComponent();
        }
        $this->pending->x = $x;
        $this->pending->y = $y;
        $this->pending->z = $z;
    }
}
<?php

declare(strict_types=1);

namespace pocketmine\domain\component;

use pocketmine\domain\ecs\Component;

#[Component]
final class RotationComponent {
    public function __construct(
        public float $yaw = 0.0,
        public float $pitch = 0.0,
        public float $headYaw = 0.0,
    ) {}

    public function setYaw(float $yaw): void {
        $this->yaw = $this->normalizeYaw($yaw);
    }

    public function setPitch(float $pitch): void {
        $this->pitch = max(-90.0, min(90.0, $pitch));
    }

    public function setHeadYaw(float $headYaw): void {
        $this->headYaw = $this->normalizeYaw($headYaw);
    }

    private function normalizeYaw(float $yaw): float {
        $yaw = fmod($yaw, 360.0);
        return $yaw < 0 ? $yaw + 360.0 : $yaw;
    }

    public function getForwardVector(): array {
        $yawRad = deg2rad($this->yaw);
        $pitchRad = deg2rad($this->pitch);
        $cosPitch = cos($pitchRad);
        return [
            -sin($yawRad) * $cosPitch,
            -sin($pitchRad),
            cos($yawRad) * $cosPitch,
        ];
    }
}
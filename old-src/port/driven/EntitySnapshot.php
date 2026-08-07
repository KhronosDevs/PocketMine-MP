<?php

declare(strict_types=1);

namespace pocketmine\port\driven;

final class EntitySnapshot {
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly float $x,
        public readonly float $y,
        public readonly float $z,
        public readonly float $yaw,
        public readonly float $pitch,
        public readonly array $components, // componentType => componentData
    ) {}
}
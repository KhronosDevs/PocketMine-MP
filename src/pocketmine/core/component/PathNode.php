<?php

declare(strict_types=1);

namespace pocketmine\core\component;

use pocketmine\core\ecs\Component;

#[Component]
final class PathNode {
    public function __construct(
        public float $x,
        public float $y,
        public float $z,
        public int $type = 0, // 0=walk, 1=jump, 2=swim, 3=climb
        public float $cost = 1.0,
    ) {}

    public function toArray(): array {
        return [
            'x' => $this->x,
            'y' => $this->y,
            'z' => $this->z,
            'type' => $this->type,
            'cost' => $this->cost,
        ];
    }

    public static function fromArray(array $data): self {
        return new self(
            $data['x'],
            $data['y'],
            $data['z'],
            $data['type'] ?? 0,
            $data['cost'] ?? 1.0,
        );
    }
}

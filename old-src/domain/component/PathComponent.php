<?php

declare(strict_types=1);

namespace pocketmine\domain\component;

use pocketmine\domain\ecs\Component;

#[Component]
final class PathComponent {
    /** @var array<int, PathNode> */
    public array $nodes = [];
    public int $currentIndex = 0;
    public bool $recalculate = false;
    public float $targetX = 0.0;
    public float $targetY = 0.0;
    public float $targetZ = 0.0;
    public int $maxNodes = 50;

    public function __construct() {}

    public function setPath(array $nodes): void {
        $this->nodes = $nodes;
        $this->currentIndex = 0;
    }

    public function getCurrentNode(): ?PathNode {
        return $this->nodes[$this->currentIndex] ?? null;
    }

    public function advance(): void {
        $this->currentIndex++;
    }

    public function isComplete(): bool {
        return $this->currentIndex >= count($this->nodes);
    }

    public function getRemainingDistance(float $x, float $y, float $z): float {
        $dist = 0.0;
        $prevX = $x;
        $prevY = $y;
        $prevZ = $z;

        for ($i = $this->currentIndex; $i < count($this->nodes); $i++) {
            $node = $this->nodes[$i];
            $dx = $node->x - $prevX;
            $dy = $node->y - $prevY;
            $dz = $node->z - $prevZ;
            $dist += sqrt($dx * $dx + $dy * $dy + $dz * $dz);
            $prevX = $node->x;
            $prevY = $node->y;
            $prevZ = $node->z;
        }
        return $dist;
    }

    public function toArray(): array {
        return [
            'nodes' => array_map(fn(PathNode $n) => $n->toArray(), $this->nodes),
            'currentIndex' => $this->currentIndex,
            'targetX' => $this->targetX,
            'targetY' => $this->targetY,
            'targetZ' => $this->targetZ,
        ];
    }

    public static function fromArray(array $data): self {
        $path = new self();
        $path->nodes = array_map(fn(array $n) => PathNode::fromArray($n), $data['nodes'] ?? []);
        $path->currentIndex = $data['currentIndex'] ?? 0;
        $path->targetX = $data['targetX'] ?? 0.0;
        $path->targetY = $data['targetY'] ?? 0.0;
        $path->targetZ = $data['targetZ'] ?? 0.0;
        return $path;
    }
}

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
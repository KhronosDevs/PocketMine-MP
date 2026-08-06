<?php

declare(strict_types=1);

namespace pocketmine\domain\component;

use pocketmine\domain\ecs\Component;

#[Component]
final class AIStateComponent {
    public int $state = 0; // 0=idle, 1=wandering, 2=pathfinding, 3=attacking, 4=fleeing, 5=interacting
    public ?int $targetEntity = null;
    public float $targetX = 0.0;
    public float $targetY = 0.0;
    public float $targetZ = 0.0;
    public int $pathIndex = 0;
    /** @var array<int, array{x:float,y:float,z:float}> */
    public array $path = [];
    public int $updateCounter = 0;
    public float $speedModifier = 1.0;
    public bool $canNavigate = true;
    public bool $avoidWater = false;
    public bool $avoidFire = true;
    public float $followRange = 16.0;
    public float $attackRange = 2.0;

    public function __construct() {}

    public function setTargetEntity(int $entityId): void {
        $this->targetEntity = $entityId;
        $this->state = 3; // attacking/following
    }

    public function setTargetPosition(float $x, float $y, float $z): void {
        $this->targetX = $x;
        $this->targetY = $y;
        $this->targetZ = $z;
        $this->targetEntity = null;
        $this->state = 2; // pathfinding
    }

    public function clearTarget(): void {
        $this->targetEntity = null;
        $this->targetX = 0.0;
        $this->targetY = 0.0;
        $this->targetZ = 0.0;
        $this->state = 0; // idle
    }

    public function setPath(array $path): void {
        $this->path = $path;
        $this->pathIndex = 0;
    }

    public function getNextPathPoint(): ?array {
        if ($this->pathIndex >= count($this->path)) {
            return null;
        }
        return $this->path[$this->pathIndex++];
    }

    public function hasPath(): bool {
        return $this->pathIndex < count($this->path);
    }

    public function toArray(): array {
        return [
            'state' => $this->state,
            'targetEntity' => $this->targetEntity,
            'targetX' => $this->targetX,
            'targetY' => $this->targetY,
            'targetZ' => $this->targetZ,
            'path' => $this->path,
            'pathIndex' => $this->pathIndex,
            'speedModifier' => $this->speedModifier,
        ];
    }
}
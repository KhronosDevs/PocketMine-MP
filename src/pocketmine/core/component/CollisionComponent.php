<?php

declare(strict_types=1);

namespace pocketmine\core\component;

use pocketmine\core\ecs\Component;

#[Component]
final class CollisionComponent {
    public function __construct(
        public float $width = 0.6,
        public float $height = 1.8,
        public float $eyeHeight = 1.62,
        public bool $canCollide = true,
        public bool $collidesWithEntities = true,
        public bool $collidesWithBlocks = true,
    ) {}

    public function getHalfWidth(): float {
        return $this->width / 2.0;
    }

    public function getBoundingBox(float $x, float $y, float $z): array {
        $hw = $this->getHalfWidth();
        return [
            'minX' => $x - $hw,
            'minY' => $y,
            'minZ' => $z - $hw,
            'maxX' => $x + $hw,
            'maxY' => $y + $this->height,
            'maxZ' => $z + $hw,
        ];
    }

    public function intersects(CollisionComponent $other, float $x1, float $y1, float $z1, float $x2, float $y2, float $z2): bool {
        $bb1 = $this->getBoundingBox($x1, $y1, $z1);
        $bb2 = $other->getBoundingBox($x2, $y2, $z2);
        return $bb1['minX'] < $bb2['maxX'] && $bb1['maxX'] > $bb2['minX'] &&
               $bb1['minY'] < $bb2['maxY'] && $bb1['maxY'] > $bb2['minY'] &&
               $bb1['minZ'] < $bb2['maxZ'] && $bb1['maxZ'] > $bb2['minZ'];
    }
}
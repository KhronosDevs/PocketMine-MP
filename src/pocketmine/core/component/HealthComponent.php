<?php

declare(strict_types=1);

namespace pocketmine\core\component;

use pocketmine\core\ecs\Component;

#[Component]
final class HealthComponent {
    public function __construct(
        public float $current = 20.0,
        public float $max = 20.0,
    ) {}
}
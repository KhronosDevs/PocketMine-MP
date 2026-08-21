<?php

declare(strict_types=1);

namespace pocketmine\core\component;

use pocketmine\core\ecs\Component;

/**
 * Marks an entity as currently on fire. Ticks remaining until the flame goes
 * out; EnvironmentalDamageSystem applies CAUSE_FIRE_TICK damage while it
 * lasts, and the network layer mirrors the state as the DATA_FLAG_ONFIRE
 * metadata flag so clients render the flames.
 */
#[Component]
final class FireComponent {
    public function __construct(
        public int $ticks = 0,
    ) {}
}

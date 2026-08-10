<?php

declare(strict_types=1);

namespace pocketmine\core\component;

use pocketmine\core\ecs\Component;

/**
 * Player food stats (14.11). Mirrors the legacy Human attribute trio:
 * hunger (the food bar, 0..20), saturation (hidden, consumed first by
 * exhaustion) and exhaustion (accumulates from actions; every 4.0 points
 * costs 1 saturation, else 1 hunger). lastSynced* let the HungerSystem
 * avoid re-sending the HUD attributes every tick when nothing moved.
 */
#[Component]
final class HungerComponent {
    public function __construct(
        public float $hunger = 20.0,
        public float $saturation = 5.0,
        public float $exhaustion = 0.0,
        public float $lastSyncedHunger = 20.0,
        public float $lastSyncedSaturation = 5.0,
    ) {}
}

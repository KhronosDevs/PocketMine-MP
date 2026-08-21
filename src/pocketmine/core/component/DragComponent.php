<?php

declare(strict_types=1);

namespace pocketmine\core\component;

/**
 * Marks an entity as having air drag (friction) applied each tick.
 * Used for dropped items: old-src applies 0.02 drag (0.98 friction/tick)
 * to items only — mobs and other entities have no horizontal drag.
 */
final class DragComponent {
    public function __construct(
        /** Friction multiplier per tick (0.98 = 2% drag). */
        public float $friction = 0.98,
    ) {}
}

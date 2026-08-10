<?php

declare(strict_types=1);

namespace pocketmine\core\component;

use pocketmine\core\ecs\Component;

/**
 * Which world an entity belongs to (multi-world, 14.20).
 *
 * The ECS world holds every entity of every world; this component is the
 * discriminator that routes chunk reads/writes, entity broadcasts and time to
 * the right world bundle. World ids come from the WorldRegistry; the default
 * world is always id 0.
 */
#[Component]
final class WorldComponent {
    public function __construct(
        public int $id = 0,
    ) {}
}

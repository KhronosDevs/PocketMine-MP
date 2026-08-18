<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\threading;

use pmmp\thread\ThreadSafe;

/**
 * Thread-safe snapshot of archetype component data.
 *
 * pmmpthread v6 ThreadSafe properties may only hold scalars (int, float,
 * string, bool), null, or other ThreadSafe instances — NOT PHP arrays.
 * Component data is transported as JSON-encoded strings and decoded on the
 * worker thread.
 */
final class ArchetypeSnapshot extends ThreadSafe {
    /** JSON-encoded flat float arrays for positions, velocities, and entity IDs. */
    public string $data = '';
    public int $count = 0;
    public float $deltaTime = 0.0;

    /**
     * @param array{entityIds: int[], positionsX: float[], positionsY: float[], positionsZ: float[], velocitiesX: float[], velocitiesY: float[], velocitiesZ: float[]} $payload
     */
    public static function fromPayload(array $payload, int $count, float $deltaTime): self {
        $snap = new self();
        $snap->data = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $snap->count = $count;
        $snap->deltaTime = $deltaTime;
        return $snap;
    }

    /**
     * @return array{entityIds: int[], positionsX: float[], positionsY: float[], positionsZ: float[], velocitiesX: float[], velocitiesY: float[], velocitiesZ: float[]}
     */
    public function getPayload(): array {
        return json_decode($this->data, true, 512, JSON_THROW_ON_ERROR);
    }
}

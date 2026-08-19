<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\threading;

use pmmp\thread\ThreadSafe;

/**
 * Thread-safe snapshot of archetype component data.
 *
 * pmmpthread v6 ThreadSafe properties may only hold scalars (int, float,
 * string, bool), null, or other ThreadSafe instances — NOT PHP arrays.
 * Component data is transported as a packed binary blob (see SnapshotCodec)
 * and decoded on the worker thread.
 */
final class ArchetypeSnapshot extends ThreadSafe {
    /** Packed binary flat float arrays for positions and velocities. */
    public string $data = '';
    public int $count = 0;
    public float $deltaTime = 0.0;

    /**
     * @param array{positionsX: float[], positionsY: float[], positionsZ: float[], velocitiesX: float[], velocitiesY: float[], velocitiesZ: float[]} $payload
     */
    public static function fromPayload(array $payload, int $count, float $deltaTime): self {
        $snap = new self();
        $snap->data = SnapshotCodec::encode([
            $payload['positionsX'],
            $payload['positionsY'],
            $payload['positionsZ'],
            $payload['velocitiesX'],
            $payload['velocitiesY'],
            $payload['velocitiesZ'],
        ]);
        $snap->count = $count;
        $snap->deltaTime = $deltaTime;
        return $snap;
    }

    /**
     * @return array{positionsX: float[], positionsY: float[], positionsZ: float[], velocitiesX: float[], velocitiesY: float[], velocitiesZ: float[]}
     */
    public function getPayload(): array {
        [$px, $py, $pz, $vx, $vy, $vz] = SnapshotCodec::decode($this->data);
        return [
            'positionsX' => $px,
            'positionsY' => $py,
            'positionsZ' => $pz,
            'velocitiesX' => $vx,
            'velocitiesY' => $vy,
            'velocitiesZ' => $vz,
        ];
    }
}

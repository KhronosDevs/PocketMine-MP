<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\threading;

use pmmp\thread\ThreadSafe;

/**
 * Thread-safe result cell for one parallel system computation.
 *
 * The worker writes computed pending values into a packed binary blob;
 * the main thread reads and decodes them, then applies to pending fields.
 * Follows the same pattern as ChunkGenResult used by world generation.
 */
final class ParallelResult extends ThreadSafe {
    /** Packed binary pending position + velocity writes. */
    public string $data = '';
    public int $count = 0;
    public bool $done = false;
    public ?string $error = null;

    /**
     * @param array{pendingPositionsX: float[], pendingPositionsY: float[], pendingPositionsZ: float[], pendingVelocitiesX: float[], pendingVelocitiesY: float[], pendingVelocitiesZ: float[]} $payload
     */
    public function setPayload(array $payload, int $count): void {
        $this->data = SnapshotCodec::encode([
            $payload['pendingPositionsX'],
            $payload['pendingPositionsY'],
            $payload['pendingPositionsZ'],
            $payload['pendingVelocitiesX'],
            $payload['pendingVelocitiesY'],
            $payload['pendingVelocitiesZ'],
        ]);
        $this->count = $count;
    }

    /**
     * @return array{pendingPositionsX: float[], pendingPositionsY: float[], pendingPositionsZ: float[], pendingVelocitiesX: float[], pendingVelocitiesY: float[], pendingVelocitiesZ: float[]}
     */
    public function getPayload(): array {
        [$px, $py, $pz, $vx, $vy, $vz] = SnapshotCodec::decode($this->data);
        return [
            'pendingPositionsX' => $px,
            'pendingPositionsY' => $py,
            'pendingPositionsZ' => $pz,
            'pendingVelocitiesX' => $vx,
            'pendingVelocitiesY' => $vy,
            'pendingVelocitiesZ' => $vz,
        ];
    }
}

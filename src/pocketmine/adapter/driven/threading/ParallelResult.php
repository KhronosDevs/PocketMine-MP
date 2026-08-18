<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\threading;

use pmmp\thread\ThreadSafe;

/**
 * Thread-safe result cell for one parallel system computation.
 *
 * The worker writes computed pending values into a JSON-encoded string;
 * the main thread reads and decodes them, then applies to pending fields.
 * Follows the same pattern as ChunkGenResult used by world generation.
 */
final class ParallelResult extends ThreadSafe {
    /** JSON-encoded pending position + velocity writes. */
    public string $data = '';
    public int $count = 0;
    public bool $done = false;
    public ?string $error = null;

    /**
     * @param array{pendingPositionsX: float[], pendingPositionsY: float[], pendingPositionsZ: float[], pendingVelocitiesX: float[], pendingVelocitiesY: float[], pendingVelocitiesZ: float[]} $payload
     */
    public function setPayload(array $payload, int $count): void {
        $this->data = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->count = $count;
    }

    /**
     * @return array{pendingPositionsX: float[], pendingPositionsY: float[], pendingPositionsZ: float[], pendingVelocitiesX: float[], pendingVelocitiesY: float[], pendingVelocitiesZ: float[]}
     */
    public function getPayload(): array {
        return json_decode($this->data, true, 512, JSON_THROW_ON_ERROR);
    }
}

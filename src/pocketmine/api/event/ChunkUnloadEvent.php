<?php

declare(strict_types=1);

namespace pocketmine\api\event;

/**
 * Fires before a chunk is unloaded and persisted to disk. Cancelling keeps
 * the chunk resident in memory (it will be re-attempted later).
 */
class ChunkUnloadEvent extends CancellableEvent {
    public function __construct(
        public readonly int $chunkX,
        public readonly int $chunkZ,
        public readonly int $worldId,
    ) {}

    public function getChunkX(): int {
        return $this->chunkX;
    }

    public function getChunkZ(): int {
        return $this->chunkZ;
    }

    public function getWorldId(): int {
        return $this->worldId;
    }
}

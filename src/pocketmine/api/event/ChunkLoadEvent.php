<?php

declare(strict_types=1);

namespace pocketmine\api\event;

/**
 * Fires when a chunk is loaded (from disk or freshly generated). Carries
 * the chunk coordinates and world id. Informational - plugins use it to
 * hook chunk-level logic (e.g. spawning structures when a chunk materializes).
 */
class ChunkLoadEvent extends Event {
    public function __construct(
        public readonly int $chunkX,
        public readonly int $chunkZ,
        public readonly int $worldId,
        public readonly bool $isNewChunk,
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

    public function isNewChunk(): bool {
        return $this->isNewChunk;
    }
}

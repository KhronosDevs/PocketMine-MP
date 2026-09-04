<?php

declare(strict_types=1);

namespace pocketmine\core\service;

use pocketmine\core\ecs\World;
use pocketmine\core\resource\WorldRegistry;
use pocketmine\port\driven\ChunkData;
use pocketmine\port\driven\StoragePort;

/**
 * Deferred chunk-save queue.
 *
 * A chunk save costs ~0.4 ms (NBT encode + zlib + region-file write), and the
 * old path ran it synchronously wherever a save was triggered: the periodic
 * full-world autosave froze the server for hundreds of ms (every resident
 * chunk inline), and the unload sweeps could burst up to 64 evictions in one
 * tick (~25 ms). This service decouples "state changed, must persist" from
 * "write it to disk now":
 *
 *  - Callers queueSave() a ChunkData DTO (a snapshot - the chunk can be
 *    dropped from memory immediately after).
 *  - The kernel drains a small budget each tick (DEFAULT_DRAIN_PER_TICK),
 *    spreading the ~0.4 ms writes so no single tick stalls.
 *  - Entries coalesce by (world, chunk): re-dirtying a chunk before its save
 *    landed replaces the pending DTO, so the newest state wins and a chunk is
 *    written at most once per dirty period.
 *  - A reload of a chunk that still has a pending save must NEVER read the
 *    stale disk copy (it would overwrite the pending state later). The load
 *    path calls takePending() and hydrates from the DTO instead; the pending
 *    write is dropped, so there is no lost update.
 *  - flushAll() (shutdown, /save-all, world unload) writes everything
 *    synchronously so persistence points stay exact.
 */
final class ChunkSaveService {
    /** How many pending saves the kernel flushes per tick. */
    public const DEFAULT_DRAIN_PER_TICK = 4;

    /** @var array<string, ChunkData> "worldId:chunkX:chunkZ" => pending DTO (newest wins) */
    private array $pending = [];

    public function __construct(
        private readonly World $world,
        private readonly StoragePort $storagePort,
    ) {}

    /**
     * Queue a chunk snapshot for the next background drain. The caller has
     * already captured the DTO (store->toChunkData + tile/entity snapshots),
     * so the chunk may be unloaded from memory immediately.
     */
    public function queueSave(int $worldId, int $chunkX, int $chunkZ, ChunkData $data): void {
        $this->pending[$worldId . ':' . $chunkX . ':' . $chunkZ] = $data;
    }

    public function pendingCount(): int {
        return count($this->pending);
    }

    public function isPending(int $worldId, int $chunkX, int $chunkZ): bool {
        return isset($this->pending[$worldId . ':' . $chunkX . ':' . $chunkZ]);
    }

    /**
     * Consume and return the pending DTO for a chunk, or null when none is
     * queued. Used by the load path: a chunk that was unloaded with a pending
     * save must rehydrate from this DTO (the latest known state) and the
     * pending disk write is cancelled, so the disk copy can never overwrite
     * newer in-memory edits with stale queued data.
     */
    public function takePending(int $worldId, int $chunkX, int $chunkZ): ?ChunkData {
        $key = $worldId . ':' . $chunkX . ':' . $chunkZ;
        $data = $this->pending[$key] ?? null;
        if ($data instanceof ChunkData) {
            unset($this->pending[$key]);
        }
        return $data;
    }

    /**
     * Write up to $max pending chunks now (oldest first - PHP arrays keep
     * insertion order). Each write is ~0.4 ms, so the per-tick budget keeps
     * the drain off the critical path.
     */
    public function drainBudget(int $max): int {
        $saved = 0;
        foreach ($this->pending as $key => $data) {
            if ($saved >= $max) {
                break;
            }
            [$worldId, $chunkX, $chunkZ] = array_map('intval', explode(':', $key, 3));
            $this->getStorage($worldId)->saveChunk($chunkX, $chunkZ, $data);
            unset($this->pending[$key]);
            $saved++;
        }
        return $saved;
    }

    /** Write every pending chunk synchronously. Returns how many were saved. */
    public function flushAll(): int {
        return $this->drainBudget(PHP_INT_MAX);
    }

    private function getStorage(int $worldId = 0): StoragePort {
        if ($worldId !== 0) {
            $registry = $this->world->getResourceRegistry()->get(WorldRegistry::class);
            $storage = $registry instanceof WorldRegistry ? $registry->getStorage($worldId) : null;
            return $storage ?? $this->storagePort;
        }
        return $this->storagePort;
    }
}

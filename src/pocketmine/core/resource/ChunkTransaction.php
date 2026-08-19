<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

/**
 * Buffers block and biome changes across one or more chunks, applying them
 * in bulk on commit(). Designed for WorldEdit-style region operations where
 * calling setBlock() per-position would trigger redundant light recalculations
 * and cache invalidations:
 *
 *   $tx = new ChunkTransaction($store);
 *   foreach ($region->positions() as [$x, $y, $z]) {
 *       $tx->setBlock($x, $y, $z, BlockIds::STONE, 0);
 *   }
 *   $tx->commit();   // one light pass + cache invalidation per affected chunk
 *
 * For undo support:
 *   $tx->commit();   // apply
 *   // ... later ...
 *   $tx->rollback(); // restore pre-transaction state
 *
 * Light recalculation: LightCalculator::calculate() only supports full-chunk
 * recalculation (no Y-range optimization exists). Each affected chunk gets
 * exactly ONE recalculateLight() call on commit — a massive improvement over
 * the per-block N calls in the naive path.
 *
 * Lifecycle: created → setBlock()/setBiome()... → commit() → [rollback()]
 * After commit(), the transaction is spent — create a new one.
 */
final class ChunkTransaction {

    private ChunkStore $store;
    private BlockRegistry $registry;

    /**
     * Per-chunk buffered block/meta changes.
     * Keyed by chunk key ("chunkX:chunkZ"), values are
     * index => [blockId, meta].
     * @var array<string, array<int, array{0: int, 1: int}>>
     */
    private array $blockChanges = [];

    /**
     * Pre-transaction snapshots for rollback support.
     * Keyed by chunk key, values are index => [oldBlockId, oldMeta].
     * Populated lazily on first touch per position.
     * @var array<string, array<int, array{0: int, 1: int}>>
     */
    private array $snapshots = [];

    /**
     * Pre-transaction biome snapshots for rollback support.
     * Keyed by chunk key, values are index => oldBiomeId.
     * @var array<string, array<int, int>>
     */
    private array $biomeSnapshots = [];

    /**
     * Buffered biome changes (separate from block changes).
     * Keyed by chunk key, values are index => biomeId.
     * @var array<string, array<int, int>>
     */
    private array $biomeChanges = [];

    /** @var list<array{0: int, 1: int}> cached affected chunk coordinates */
    private array $affectedChunksCache = [];

    /** Cached total change count (preserved after commit clears buffers). */
    private int $totalChangeCount = 0;

    private bool $committed = false;

    /** Whether to suppress blockListener callbacks during commit. */
    private bool $suppressBlockListener = false;

    public function __construct(ChunkStore $store, BlockRegistry $registry) {
        $this->store = $store;
        $this->registry = $registry;
    }

    /**
     * Suppress blockListener (fluid system) callbacks during commit().
     * Useful for large bulk operations where per-block fluid updates are
     * undesirable — the plugin can trigger its own fluid logic afterward.
     */
    public function suppressBlockListener(): void {
        $this->suppressBlockListener = true;
    }

    /**
     * Buffer a block change. Does NOT touch live chunk data.
     * Lazily snapshots the pre-transaction block/meta at that position
     * the first time it is touched (for rollback).
     *
     * @return bool true if the chunk is loaded and the change was buffered
     */
    public function setBlock(int $x, int $y, int $z, int $blockId, int $meta = 0): bool {
        if ($y < 0 || $y > 255 || $blockId < 0 || $blockId > 255) {
            return false;
        }
        $chunkX = (int)floor($x / 16);
        $chunkZ = (int)floor($z / 16);
        $key = $chunkX . ':' . $chunkZ;

        $chunk = $this->store->getChunkRef($chunkX, $chunkZ);
        if ($chunk === null) {
            return false;
        }

        $localX = $x & 15;
        $localZ = $z & 15;
        $idx = ($y << 8) | ($localZ << 4) | $localX;

        // Lazy snapshot: only capture the original value on first touch.
        if (!isset($this->blockChanges[$key][$idx])) {
            $this->snapshots[$key][$idx] = [
                ord($chunk['blocks'][$idx]),
                ord($chunk['meta'][$idx]),
            ];
        }

        $this->blockChanges[$key][$idx] = [$blockId, $meta];
        $this->totalChangeCount++;
        return true;
    }

    /**
     * Buffer a biome change. Does NOT touch live chunk data.
     * Lazily snapshots the pre-transaction biome on first touch.
     */
    public function setBiome(int $x, int $z, int $biome): bool {
        $chunkX = (int)floor($x / 16);
        $chunkZ = (int)floor($z / 16);
        $key = $chunkX . ':' . $chunkZ;

        $chunk = $this->store->getChunkRef($chunkX, $chunkZ);
        if ($chunk === null) {
            return false;
        }

        $idx = ($z & 15) * 16 + ($x & 15);

        if (!isset($this->biomeChanges[$key][$idx])) {
            $this->biomeSnapshots[$key][$idx] = ord($chunk['biomes'][$idx]);
        }

        $this->biomeChanges[$key][$idx] = $biome;
        $this->totalChangeCount++;
        return true;
    }

    /**
     * Apply all buffered changes to live chunk data.
     *
     * Per affected chunk:
     *   1. Bulk-write all block+meta changes in one pass.
     *   2. ONE recalculateLight() (full-chunk — no ranged optimization exists).
     *   3. ONE wire/compressedBatch cache invalidation.
     *   4. ONE lightDirty mark.
     *   5. Fire blockListener per changed position (unless suppressed).
     *
     * After committing, the change buffers are freed but the snapshot data
     * is retained for rollback() support. The totalChangeCount and
     * affectedChunks list are cached for post-commit introspection.
     */
    public function commit(): void {
        if ($this->committed) {
            return;
        }
        $this->committed = true;

        // Cache affected chunks before clearing buffers.
        $this->affectedChunksCache = $this->getAffectedChunks();

        foreach ($this->blockChanges as $key => $changes) {
            [$chunkX, $chunkZ] = explode(':', $key, 2);
            $chunkX = (int)$chunkX;
            $chunkZ = (int)$chunkZ;

            // 1. Bulk-write block+meta.
            $this->store->writeBlockBatch($chunkX, $chunkZ, $changes);

            // 2. ONE full-chunk light recalculation.
            $this->store->recalculateLight($chunkX, $chunkZ, $this->registry);

            // 3. ONE cache invalidation.
            $this->store->touchChunkCaches($chunkX, $chunkZ);

            // 4. ONE lightDirty mark (recalculateLight already does this when
            //    light changes, but if light was identical we still need to
            //    mark dirty so the wire re-serialization picks up block changes).
            $this->store->markLightDirty($chunkX, $chunkZ);

            // 5. Fire blockListener per changed position.
            if (!$this->suppressBlockListener) {
                foreach ($changes as $idx => [$id, $m]) {
                    $y = $idx >> 8;
                    $xz = $idx & 0xFF;
                    $localZ = $xz >> 4;
                    $localX = $xz & 0x0F;
                    $worldX = $chunkX * 16 + $localX;
                    $worldZ = $chunkZ * 16 + $localZ;
                    $this->store->fireBlockListener($worldX, $y, $worldZ, $id);
                }
            }
        }

        // Apply biome changes.
        foreach ($this->biomeChanges as $key => $changes) {
            [$chunkX, $chunkZ] = explode(':', $key, 2);
            $chunkX = (int)$chunkX;
            $chunkZ = (int)$chunkZ;

            $this->store->writeBiomeBatch($chunkX, $chunkZ, $changes);
            $this->store->touchChunkCaches($chunkX, $chunkZ);
        }

        // Free change buffers (snapshots retained for rollback).
        $this->blockChanges = [];
        $this->biomeChanges = [];
    }

    /**
     * Restore all touched chunks to their pre-transaction snapshot.
     * Must work even after commit() has already been called (undo support).
     * Does NOT discard snapshot data — only the change buffers were freed
     * on commit, so rollback reads from snapshots.
     */
    public function rollback(): void {
        // Restore block/meta from snapshots.
        foreach ($this->snapshots as $key => $positions) {
            [$chunkX, $chunkZ] = explode(':', $key, 2);
            $chunkX = (int)$chunkX;
            $chunkZ = (int)$chunkZ;

            $this->store->writeBlockBatch($chunkX, $chunkZ, $positions);
            $this->store->recalculateLight($chunkX, $chunkZ, $this->registry);
            $this->store->touchChunkCaches($chunkX, $chunkZ);
            $this->store->markLightDirty($chunkX, $chunkZ);

            // Fire blockListener for restored positions.
            if (!$this->suppressBlockListener) {
                foreach ($positions as $idx => [$id, $m]) {
                    $y = $idx >> 8;
                    $xz = $idx & 0xFF;
                    $localZ = $xz >> 4;
                    $localX = $xz & 0x0F;
                    $worldX = $chunkX * 16 + $localX;
                    $worldZ = $chunkZ * 16 + $localZ;
                    $this->store->fireBlockListener($worldX, $y, $worldZ, $id);
                }
            }
        }

        // Restore biomes from snapshots.
        foreach ($this->biomeSnapshots as $key => $positions) {
            [$chunkX, $chunkZ] = explode(':', $key, 2);
            $chunkX = (int)$chunkX;
            $chunkZ = (int)$chunkZ;

            $this->store->writeBiomeBatch($chunkX, $chunkZ, $positions);
            $this->store->touchChunkCaches($chunkX, $chunkZ);
        }

        // Clear everything — rollback is a terminal operation.
        $this->blockChanges = [];
        $this->biomeChanges = [];
        $this->snapshots = [];
        $this->biomeSnapshots = [];
        $this->totalChangeCount = 0;
        $this->affectedChunksCache = [];
    }

    /**
     * Total buffered block+biome changes across all chunks.
     * Cached before commit clears buffers.
     */
    public function getChangeCount(): int {
        return $this->totalChangeCount;
    }

    /**
     * List of [chunkX, chunkZ] pairs touched by this transaction.
     * Cached before commit clears buffers.
     *
     * @return list<array{0: int, 1: int}>
     */
    public function getAffectedChunks(): array {
        if ($this->affectedChunksCache !== []) {
            return $this->affectedChunksCache;
        }
        $chunks = [];
        $seen = [];
        foreach (array_keys($this->blockChanges) as $key) {
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                [$x, $z] = explode(':', $key, 2);
                $chunks[] = [(int)$x, (int)$z];
            }
        }
        foreach (array_keys($this->biomeChanges) as $key) {
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                [$x, $z] = explode(':', $key, 2);
                $chunks[] = [(int)$x, (int)$z];
            }
        }
        return $chunks;
    }

    public function isCommitted(): bool {
        return $this->committed;
    }
}

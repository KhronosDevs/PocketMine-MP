<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

use pocketmine\core\ecs\Resource;
use pocketmine\port\driven\ChunkData;
use pocketmine\protocol\ChunkSerializer;
use function array_fill;
use function array_key_first;
use function array_keys;
use function chr;
use function count;
use function explode;
use function ltrim;
use function ord;
use function str_repeat;
use function strlen;
use function substr;

/**
 * In-memory store of loaded chunk block data.
 *
 * Chunk block/meta/light data is kept as flat binary strings (1 byte per
 * block, Y-major index) so reads and writes are O(1) string-offset
 * operations. Biomes are a 256-byte string; heightmap is derived on
 * demand. ChunkData DTOs remain the persistence/wire format: the store is
 * populated from them on load and produces them again on save.
 */
#[Resource]
final class ChunkStore {
    public const CHUNK_BLOCK_COUNT = 65536; // 16 * 16 * 256
    public const SECTION_BYTES = 4096;      // 16 * 16 * 16
    public const LIGHT_BYTES = 2048;        // 16 * 16 * 8 (nibble packed)
    public const BIOME_COUNT = 256;

    /** @var array<string, array<string, mixed>> chunkKey => chunk record */
    private array $chunks = [];

    /**
     * Optional observer fired on every setBlock(x, y, z, id) so the fluid
     * system can register newly placed liquids without rescanning all blocks
     * of a chunk. Set once at boot by the Kernel.
     * @var (callable(int, int, int, int): void)|null
     */
    private $blockListener = null;

    public function setBlockListener(?callable $listener): void {
        $this->blockListener = $listener;
    }

    public function isLoaded(int $chunkX, int $chunkZ): bool {
        return isset($this->chunks[$this->key($chunkX, $chunkZ)]);
    }

    public function getCount(): int {
        return count($this->chunks);
    }

    /**
     * Coordinates of the longest-loaded chunk (PHP arrays preserve insertion
     * order, so the first key is the oldest). Used for FIFO eviction when the
     * loaded-chunk budget is exceeded.
     *
     * @return array{0: int, 1: int}|null null when the store is empty
     */
    public function getOldestLoadedChunk(): ?array {
        $key = array_key_first($this->chunks);
        if ($key === null) {
            return null;
        }
        $parts = explode(':', $key, 2);
        return [(int)$parts[0], (int)$parts[1]];
    }

    /**
     * Every loaded chunk's coordinates, for full-world saves (14.4).
     *
     * @return list<array{0: int, 1: int}>
     */
    public function getLoadedChunkCoordinates(): array {
        $out = [];
        foreach (array_keys($this->chunks) as $key) {
            $parts = explode(':', $key, 2);
            $out[] = [(int)$parts[0], (int)$parts[1]];
        }
        return $out;
    }

    /**
     * Approximate resident memory of every loaded chunk's payload (block,
     * meta, light, biome binary strings). Each loaded chunk holds ~160KB of
     * strings, so this is the dominant term in the chunk-budget equation.
     */
    public function getMemoryEstimate(): int {
        $bytes = 0;
        foreach ($this->chunks as $chunk) {
            $bytes += strlen((string)$chunk['blocks'])
                + strlen((string)$chunk['meta'])
                + strlen((string)$chunk['skyLight'])
                + strlen((string)$chunk['blockLight'])
                + strlen((string)$chunk['biomes'])
                + strlen((string)($chunk['wire'] ?? '')); // serialized wire payload
        }
        return $bytes;
    }

    /**
     * The protocol-84 wire payload for a chunk, cached on the record and
     * invalidated by any mutation (setBlock/setBiome/light recalc). Multiple
     * viewers and the per-tick light-dirty flush share the cached bytes, so
     * serialize runs once per chunk instead of once per send. The payload is
     * world-scoped (this store IS one world) so no world key is needed.
     */
    public function getSerializedWire(int $chunkX, int $chunkZ): ?string {
        $key = $this->key($chunkX, $chunkZ);
        if (!isset($this->chunks[$key])) {
            return null;
        }
        $chunk = $this->chunks[$key];
        if (($chunk['wire'] ?? null) === null) {
            $data = $this->toChunkData($chunkX, $chunkZ);
            if ($data === null) {
                return null;
            }
            $chunk['wire'] = ChunkSerializer::serialize($data, $this->hasSky);
            $this->chunks[$key] = $chunk;
        }
        return $chunk['wire'];
    }

    /**
     * Cached compressed batch payload for a chunk (after BatchPacket::encode).
     * Invalidated alongside the wire cache on any mutation. Multiple players
     * viewing the same chunk share this single compressed blob.
     */
    public function getCompressedBatch(int $chunkX, int $chunkZ): ?string {
        $key = $this->key($chunkX, $chunkZ);
        return $this->chunks[$key]['compressedBatch'] ?? null;
    }

    /** Cache a compressed batch payload for a chunk. */
    public function cacheCompressedBatch(int $chunkX, int $chunkZ, string $batch): void {
        $key = $this->key($chunkX, $chunkZ);
        if (isset($this->chunks[$key])) {
            $this->chunks[$key]['compressedBatch'] = $batch;
        }
    }

    /**
     * Populate (or replace) the in-memory representation from a ChunkData DTO.
     */
    public function load(ChunkData $data): void {
        $blocks = str_repeat("\x00", self::CHUNK_BLOCK_COUNT);
        $meta = str_repeat("\x00", self::CHUNK_BLOCK_COUNT);
        $skyLight = str_repeat("\xff", self::LIGHT_BYTES * 16);
        $blockLight = str_repeat("\x00", self::LIGHT_BYTES * 16);

        foreach ($data->sections as $section) {
            $sy = (int)$section['y'];
            if ($sy < 0 || $sy > 15) {
                continue;
            }
            $offset = $sy * self::SECTION_BYTES;
            $blocks = substr_replace($blocks, (string)$section['blocks'], $offset, self::SECTION_BYTES);
            $meta = substr_replace($meta, (string)($section['data'] ?? str_repeat("\x00", self::SECTION_BYTES)), $offset, self::SECTION_BYTES);

            $lightOffset = $sy * self::LIGHT_BYTES;
            $skyLight = substr_replace($skyLight, (string)($section['skyLight'] ?? str_repeat("\xff", self::LIGHT_BYTES)), $lightOffset, self::LIGHT_BYTES);
            $blockLight = substr_replace($blockLight, (string)($section['blockLight'] ?? str_repeat("\x00", self::LIGHT_BYTES)), $lightOffset, self::LIGHT_BYTES);
        }

        $biomes = str_repeat("\x00", self::BIOME_COUNT);
        foreach ($data->biomes as $i => $biome) {
            if ($i < self::BIOME_COUNT) {
                $biomes[$i] = chr($biome & 0xFF);
            }
        }

        $this->chunks[$this->key($data->chunkX, $data->chunkZ)] = [
            'x' => $data->chunkX,
            'z' => $data->chunkZ,
            'blocks' => $blocks,
            'meta' => $meta,
            'skyLight' => $skyLight,
            'blockLight' => $blockLight,
            'biomes' => $biomes,
            'heightmap' => $data->heightmap,
            'entities' => $data->entities,
            'tileEntities' => $data->tileEntities,
            'generated' => true,
            'populated' => false,
            // Wire-serialized chunk payload cache: null until first serialized,
            // invalidated on any mutation (see getSerializedWire).
            'wire' => null,
            // Compressed batch payload cache: shared across viewers.
            'compressedBatch' => null,
        ];
    }

    public function unload(int $chunkX, int $chunkZ): void {
        unset($this->chunks[$this->key($chunkX, $chunkZ)]);
    }

    public function getBlock(int $x, int $y, int $z): int {
        $chunk = $this->chunkAt($x, $y, $z);
        if ($chunk === null) {
            return 0;
        }
        $localX = $x & 15;
        $localZ = $z & 15;
        return ord($chunk['blocks'][$this->index($y, $localZ, $localX)]);
    }

    /**
     * The raw 65536-byte block-id string for a chunk (index layout
     * (y << 8) | (localZ << 4) | localX), or null when not loaded. Lets hot
     * paths scan a whole chunk with C-speed string ops instead of 65K
     * getBlock() calls (used by FluidSystem seeding).
     */
    public function getRawBlocks(int $chunkX, int $chunkZ): ?string {
        $key = $this->key($chunkX, $chunkZ);
        if (!isset($this->chunks[$key])) {
            return null;
        }
        return (string)$this->chunks[$key]['blocks'];
    }

    public function getBlockMeta(int $x, int $y, int $z): int {
        $chunk = $this->chunkAt($x, $y, $z);
        if ($chunk === null) {
            return 0;
        }
        $localX = $x & 15;
        $localZ = $z & 15;
        return ord($chunk['meta'][$this->index($y, $localZ, $localX)]);
    }

    public function setBlock(int $x, int $y, int $z, int $id, int $meta = 0): bool {
        if ($y < 0 || $y > 255 || $id < 0 || $id > 255) {
            return false;
        }
        $key = $this->key((int)floor($x / 16), (int)floor($z / 16));
        if (!isset($this->chunks[$key])) {
            return false;
        }
        $chunk = $this->chunks[$key];
        $idx = $this->index($y, $z & 15, $x & 15);
        $chunk['blocks'][$idx] = chr($id & 0xFF);
        $chunk['meta'][$idx] = chr($meta & 0xFF);
        $chunk['wire'] = null; // content changed: drop the serialized cache
        $chunk['compressedBatch'] = null; // invalidate compressed cache too

        // Incremental heightmap maintenance: avoid the O(65536) rescan in
        // toChunkData() and the O(256) scan in getHighestBlockAt().
        // Vanilla MCPE pattern: when a non-air block is placed above current
        // height, update; when the top block is removed, rescan that column.
        $localX = $x & 15;
        $localZ = $z & 15;
        $col = $localZ * 16 + $localX;
        $curHeight = $chunk['heightmap'][$col] ?? 0;
        if ($id !== 0 && $y + 1 > $curHeight) {
            $chunk['heightmap'][$col] = $y + 1;
        } elseif ($id === 0 && $y + 1 === $curHeight) {
            // Top block removed: rescan this column only (worst case 256, not 65536).
            $h = 0;
            for ($yy = $y - 1; $yy >= 0; $yy--) {
                if ($chunk['blocks'][$this->index($yy, $localZ, $localX)] !== "\x00") {
                    $h = $yy + 1;
                    break;
                }
            }
            $chunk['heightmap'][$col] = $h;
        }

        $this->chunks[$key] = $chunk;
        if ($this->blockListener !== null) {
            ($this->blockListener)($x, $y, $z, $id);
        }
        return true;
    }

    public function getBiome(int $x, int $z): int {
        $key = $this->key((int)floor($x / 16), (int)floor($z / 16));
        if (!isset($this->chunks[$key])) {
            return 0;
        }
        return ord($this->chunks[$key]['biomes'][($z & 15) * 16 + ($x & 15)]);
    }

    public function setBiome(int $x, int $z, int $biome): void {
        $key = $this->key((int)floor($x / 16), (int)floor($z / 16));
        if (!isset($this->chunks[$key])) {
            return;
        }
        $chunk = $this->chunks[$key];
        $chunk['biomes'][($z & 15) * 16 + ($x & 15)] = chr($biome & 0xFF);
        $chunk['wire'] = null; // content changed: drop the serialized cache
        $chunk['compressedBatch'] = null; // invalidate compressed cache too
        $this->chunks[$key] = $chunk;
    }

    /** @var array<string, true> chunk keys whose light changed since the last wire sync */
    private array $lightDirty = [];

    /**
     * Recompute sky + block light for one chunk from its live block grid
     * (LightCalculator). Called after a chunk is freshly generated/populated
     * and after block place/break so the wire's light arrays stay correct
     * (torches, glowstone, lava, ... actually emit). Disk-loaded chunks keep
     * their stored light for persistence round-trips.
     *
     * When the computed arrays differ from the stored ones the chunk is
     * marked light-dirty so the network layer re-sends it to viewers (a
     * placed torch must actually light up on the client).
     */
    /**
     * Whether this world has a sky (true = overworld; false = nether). The
     * flag is set once at world registration; it makes the light pipeline
     * produce zero sky light (nether darkness) and the chunk serializer send
     * dark sky-light nibbles.
     */
    private bool $hasSky = true;

    public function setHasSky(bool $hasSky): void {
        if ($this->hasSky === $hasSky) {
            return;
        }
        $this->hasSky = $hasSky;
        // The wire payload embeds the sky-light section, so a sky toggle
        // invalidates every cached serialization.
        foreach ($this->chunks as $key => $chunk) {
            $chunk['wire'] = null;
            $chunk['compressedBatch'] = null;
            $this->chunks[$key] = $chunk;
        }
    }

    public function hasSky(): bool {
        return $this->hasSky;
    }

    public function recalculateLight(int $chunkX, int $chunkZ, BlockRegistry $registry): void {
        $key = $this->key($chunkX, $chunkZ);
        if (!isset($this->chunks[$key])) {
            return;
        }
        $chunk = $this->chunks[$key];
        [$skyLight, $blockLight] = LightCalculator::calculate((string)$chunk['blocks'], $registry, $this->hasSky);
        if ($chunk['skyLight'] !== $skyLight || $chunk['blockLight'] !== $blockLight) {
            $chunk['skyLight'] = $skyLight;
            $chunk['blockLight'] = $blockLight;
            $chunk['wire'] = null; // light changed: the serialized payload is stale
            $chunk['compressedBatch'] = null; // invalidate compressed cache too
            $this->chunks[$key] = $chunk;
            $this->lightDirty[$key] = true;
        }
    }

    /**
     * Drain and clear the set of chunks whose light changed since the last
     * call (the network layer re-sends these to viewers once per tick).
     * @return list<array{0: int, 1: int}> chunk coordinates.
     */
    public function takeLightDirtyChunks(): array {
        $out = [];
        foreach (array_keys($this->lightDirty) as $key) {
            [$x, $z] = explode(':', $key);
            $out[] = [(int)$x, (int)$z];
        }
        $this->lightDirty = [];
        return $out;
    }

    /**
     * Forget that a chunk's light changed without sending it. Used when the
     * chunk was JUST streamed to a viewer with its current light arrays: the
     * freshly generated chunk is marked dirty during load (recalculateLight),
     * but the serialized payload already carries that light, so the per-tick
     * light-dirty flush must not re-serialize and re-send the same chunk.
     */
    public function clearLightDirty(int $chunkX, int $chunkZ): void {
        unset($this->lightDirty[$this->key($chunkX, $chunkZ)]);
    }

    /**
     * Sky light level (0-15) at a world position, read from the chunk's
     * packed nibble arrays (matches the LightCalculator output layout).
     */
    public function getSkyLightLevel(int $x, int $y, int $z): int {
        $chunk = $this->chunkAt($x, $y, $z);
        if ($chunk === null) {
            return 0;
        }
        return self::readLightNibble((string)$chunk['skyLight'], $y, $z & 15, $x & 15);
    }

    /** Block light level (0-15) at a world position. */
    public function getBlockLightLevel(int $x, int $y, int $z): int {
        $chunk = $this->chunkAt($x, $y, $z);
        if ($chunk === null) {
            return 0;
        }
        return self::readLightNibble((string)$chunk['blockLight'], $y, $z & 15, $x & 15);
    }

    /** Combined light level (max of sky and block) at a world position. */
    public function getLightLevel(int $x, int $y, int $z): int {
        return max($this->getSkyLightLevel($x, $y, $z), $this->getBlockLightLevel($x, $y, $z));
    }

    /**
     * Fire the blockListener callback if registered.
     * Used by ChunkTransaction::commit() to notify the fluid system
     * about individual block changes without calling setBlock() per position.
     */
    public function fireBlockListener(int $x, int $y, int $z, int $id): void {
        if ($this->blockListener !== null) {
            ($this->blockListener)($x, $y, $z, $id);
        }
    }

    /**
     * Read one nibble from a packed per-chunk light string. Block index is
     * (y << 8) | (z << 4) | x; byte = index >> 1; even index = low nibble,
     * odd index = high nibble (LightCalculator::pack layout).
     */
    private static function readLightNibble(string $packed, int $y, int $localZ, int $localX): int {
        $idx = ($y << 8) | ($localZ << 4) | $localX;
        $byte = ord($packed[$idx >> 1]);
        return ($idx & 1) === 0 ? $byte & 0x0F : $byte >> 4;
    }

    public function getHighestBlockAt(int $x, int $z): int {
        $key = $this->key((int)floor($x / 16), (int)floor($z / 16));
        if (!isset($this->chunks[$key])) {
            return 0;
        }
        $chunk = $this->chunks[$key];
        $col = ($z & 15) * 16 + ($x & 15);
        // Heightmap stores y+1 (above-surface convention). getHighestBlockAt
        // returns the y of the highest solid block, so subtract 1.
        return max(0, ($chunk['heightmap'][$col] ?? 0) - 1);
    }

    public function markGenerated(int $chunkX, int $chunkZ): void {
        $key = $this->key($chunkX, $chunkZ);
        if (isset($this->chunks[$key])) {
            $this->chunks[$key]['generated'] = true;
        }
    }

    public function markPopulated(int $chunkX, int $chunkZ): void {
        $key = $this->key($chunkX, $chunkZ);
        if (isset($this->chunks[$key])) {
            $this->chunks[$key]['populated'] = true;
        }
    }

    public function isGenerated(int $chunkX, int $chunkZ): bool {
        $key = $this->key($chunkX, $chunkZ);
        return isset($this->chunks[$key]) && $this->chunks[$key]['generated'];
    }

    public function isPopulated(int $chunkX, int $chunkZ): bool {
        $key = $this->key($chunkX, $chunkZ);
        return isset($this->chunks[$key]) && $this->chunks[$key]['populated'];
    }

    /**
     * Rebuild a ChunkData DTO from the in-memory representation so the chunk
     * can be persisted through the StoragePort.
     */
    public function toChunkData(int $chunkX, int $chunkZ): ?ChunkData {
        $key = $this->key($chunkX, $chunkZ);
        if (!isset($this->chunks[$key])) {
            return null;
        }
        $chunk = $this->chunks[$key];

        $sections = [];
        for ($sy = 0; $sy < 16; $sy++) {
            $offset = $sy * self::SECTION_BYTES;
            $sectionBlocks = substr((string)$chunk['blocks'], $offset, self::SECTION_BYTES);
            if (ltrim($sectionBlocks, "\x00") === '') {
                continue; // skip empty sections
            }
            $sections[] = [
                'y' => $sy,
                'blocks' => $sectionBlocks,
                'data' => substr((string)$chunk['meta'], $offset, self::SECTION_BYTES),
                'skyLight' => substr((string)$chunk['skyLight'], $sy * self::LIGHT_BYTES, self::LIGHT_BYTES),
                'blockLight' => substr((string)$chunk['blockLight'], $sy * self::LIGHT_BYTES, self::LIGHT_BYTES),
            ];
        }

        $biomes = [];
        for ($i = 0; $i < self::BIOME_COUNT; $i++) {
            $biomes[] = ord($chunk['biomes'][$i]);
        }

        // Heightmap is maintained incrementally by setBlock() — read directly.
        $heightmap = $chunk['heightmap'];

        return new ChunkData(
            $chunkX,
            $chunkZ,
            $sections,
            $biomes,
            $heightmap,
            $chunk['entities'],
            $chunk['tileEntities'],
        );
    }

    /**
     * Direct reference to a loaded chunk's record for bulk mutation.
     * Returns null when the chunk is not loaded — the caller must check.
     * Used by ChunkTransaction to apply buffered changes without per-block
     * setBlock overhead (wire/compressedBatch invalidation deferred to commit).
     */
    public function getChunkRef(int $chunkX, int $chunkZ): ?array {
        $key = $this->key($chunkX, $chunkZ);
        return $this->chunks[$key] ?? null;
    }

    /**
     * Bulk-write into a chunk's block and meta strings in-place.
     * Does NOT invalidate caches or call blockListener — the caller
     * (ChunkTransaction::commit) handles that.
     *
     * @param array<int, array{0: int, 1: int}> $changes index => [blockId, meta]
     */
    public function writeBlockBatch(int $chunkX, int $chunkZ, array $changes): void {
        $key = $this->key($chunkX, $chunkZ);
        if (!isset($this->chunks[$key])) {
            return;
        }
        $chunk = &$this->chunks[$key];
        foreach ($changes as $idx => [$id, $m]) {
            $chunk['blocks'][$idx] = chr($id & 0xFF);
            $chunk['meta'][$idx] = chr($m & 0xFF);
        }
    }

    /**
     * Bulk-write into a chunk's biome string in-place.
     *
     * @param array<int, int> $changes biomeIndex => biomeId
     */
    public function writeBiomeBatch(int $chunkX, int $chunkZ, array $changes): void {
        $key = $this->key($chunkX, $chunkZ);
        if (!isset($this->chunks[$key])) {
            return;
        }
        $chunk = &$this->chunks[$key];
        foreach ($changes as $idx => $biome) {
            $chunk['biomes'][$idx] = chr($biome & 0xFF);
        }
    }

    /**
     * Invalidate wire + compressedBatch caches for a chunk.
     * Called by ChunkTransaction::commit after bulk block writes.
     */
    public function touchChunkCaches(int $chunkX, int $chunkZ): void {
        $key = $this->key($chunkX, $chunkZ);
        if (!isset($this->chunks[$key])) {
            return;
        }
        $chunk = &$this->chunks[$key];
        $chunk['wire'] = null;
        $chunk['compressedBatch'] = null;
    }

    /**
     * Mark a chunk as needing light re-send to viewers.
     * Called by ChunkTransaction::commit after recalculateLight.
     */
    public function markLightDirty(int $chunkX, int $chunkZ): void {
        $this->lightDirty[$this->key($chunkX, $chunkZ)] = true;
    }

    private function chunkAt(int $x, int $y, int $z): ?array {
        if ($y < 0 || $y > 255) {
            return null;
        }
        $key = $this->key((int)floor($x / 16), (int)floor($z / 16));
        return $this->chunks[$key] ?? null;
    }

    private function index(int $y, int $localZ, int $localX): int {
        return ($y << 8) | ($localZ << 4) | $localX;
    }

    private function key(int $chunkX, int $chunkZ): string {
        return $chunkX . ':' . $chunkZ;
    }
}

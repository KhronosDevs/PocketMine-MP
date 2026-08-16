<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

use pocketmine\core\ecs\Resource;
use pocketmine\port\driven\ChunkData;
use function array_fill;
use function array_key_first;
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
                + strlen((string)$chunk['biomes']);
        }
        return $bytes;
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
        $this->chunks[$key] = $chunk;
    }

    /**
     * Recompute sky + block light for one chunk from its live block grid
     * (LightCalculator). Called after a chunk is freshly generated/populated
     * and after block place/break so the wire's light arrays stay correct
     * (torches, glowstone, lava, ... actually emit). Disk-loaded chunks keep
     * their stored light for persistence round-trips.
     */
    public function recalculateLight(int $chunkX, int $chunkZ, BlockRegistry $registry): void {
        $key = $this->key($chunkX, $chunkZ);
        if (!isset($this->chunks[$key])) {
            return;
        }
        $chunk = $this->chunks[$key];
        [$skyLight, $blockLight] = LightCalculator::calculate((string)$chunk['blocks'], $registry);
        $chunk['skyLight'] = $skyLight;
        $chunk['blockLight'] = $blockLight;
        $this->chunks[$key] = $chunk;
    }

    public function getHighestBlockAt(int $x, int $z): int {
        $key = $this->key((int)floor($x / 16), (int)floor($z / 16));
        if (!isset($this->chunks[$key])) {
            return 0;
        }
        $chunk = $this->chunks[$key];
        $localX = $x & 15;
        $localZ = $z & 15;
        for ($y = 255; $y >= 0; $y--) {
            if (ord($chunk['blocks'][$this->index($y, $localZ, $localX)]) !== 0) {
                return $y;
            }
        }
        return 0;
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

        // Recompute the heightmap from the live block data so persisted chunks
        // always match what is actually in the store after edits.
        $heightmap = [];
        for ($bz = 0; $bz < 16; $bz++) {
            for ($bx = 0; $bx < 16; $bx++) {
                $h = 0;
                for ($y = 255; $y >= 0; $y--) {
                    if (ord($chunk['blocks'][$this->index($y, $bz, $bx)]) !== 0) {
                        $h = $y + 1;
                        break;
                    }
                }
                $heightmap[$bz * 16 + $bx] = $h;
            }
        }

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

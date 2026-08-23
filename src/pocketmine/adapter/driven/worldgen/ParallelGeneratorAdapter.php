<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\worldgen;

use pocketmine\core\resource\NativeAccel;
use pocketmine\port\driven\ChunkData;
use pocketmine\port\driven\GeneratorConfig;
use pocketmine\port\driven\ThreadingPort;
use pocketmine\port\driven\WorldGenPort;
use pmmp\thread\Pool;
use function array_fill;
use function ceil;
use function chr;
use function cos;
use function floor;
use function intdiv;
use function max;
use function microtime;
use function min;
use function serialize;
use function sin;
use function str_repeat;
use function unserialize;
use function usleep;

/**
 * Self-contained procedural world generator.
 *
 * Generates terrain directly into ChunkData DTOs - no dependency on legacy
 * Server/Level/Generator classes. The generator type comes from
 * GeneratorConfig (e.g. "flat", "normal").
 *
 * Terrain (14.19): multi-octave value noise with LARGE feature cells (512 /
 * 128 / 32 blocks) so landmasses and hills read as a real world instead of a
 * patchwork of small plateaus. Every column gets a biome (plains, forest,
 * desert, taiga, ice plains, extreme hills, beach, ocean) from temperature +
 * humidity noise, and the surface profile matches the biome (sand beaches,
 * sand deserts, snow caps, ocean floors). A spawn plateau keeps the origin
 * land for any seed. The populate pass then places trees and vegetation -
 * deterministic in (chunkX, chunkZ, seed) so the world is identical after a
 * restart.
 *
 * Generation is a pure, deterministic function of (chunkX, chunkZ, type,
 * seed), so generateChunk() dispatches the work to a dedicated pmmpthread
 * Pool: each request runs as a ChunkGenerationTask on a real worker thread
 * and the caller blocks until the result arrives. The ThreadingPort stays
 * unused here because it executes tasks inline (ECS systems capture
 * non-thread-safe objects); chunk gen captures only scalars, so it can use
 * real threads.
 */
final class ParallelGeneratorAdapter implements WorldGenPort {
    public const GROUND_BLOCK = 3;   // dirt
    public const STONE_BLOCK = 1;    // stone
    public const GRASS_BLOCK = 2;    // grass
    public const WATER_BLOCK = 8;    // still water
    public const BEDROCK_BLOCK = 7;  // bedrock
    public const SAND_BLOCK = 12;
    public const GRAVEL_BLOCK = 13;
    public const SANDSTONE_BLOCK = 24;
    public const LOG_BLOCK = 17;
    public const LEAVES_BLOCK = 18;
    public const TALL_GRASS_BLOCK = 31;
    public const DEAD_BUSH_BLOCK = 32;
    public const DANDELION_BLOCK = 37;
    public const POPPY_BLOCK = 38;
    public const SNOW_LAYER_BLOCK = 78;
    public const ICE_BLOCK = 79;
    public const CACTUS_BLOCK = 81;

    /** Nether blocks (legacy protocol-84 ids). */
    public const NETHERRACK_BLOCK = 87;
    public const SOUL_SAND_BLOCK = 88;
    public const GLOWSTONE_BLOCK = 89;
    public const NETHER_QUARTZ_ORE = 153;
    public const LAVA_BLOCK = 10; // flowing lava (legacy id; NetherLava populator)

    /** Ore block ids (legacy protocol-84 ids). */
    public const COAL_ORE = 16;
    public const IRON_ORE = 15;
    public const GOLD_ORE = 14;
    public const DIAMOND_ORE = 56;
    public const REDSTONE_ORE = 73;
    public const LAPIS_ORE = 21;
    public const EMERALD_ORE = 129;
    public const STILL_LAVA_BLOCK = 11; // still lava (legacy id)

    /** Cave-carving tuning (14.x). */
    private const CAVE_GRID = 48;         // world-grid spacing of cave systems
    private const CAVE_EXTENT = 96;       // max blocks a worm can travel from its cell
    private const CAVE_MAX_SEGMENTS = 44; // cap on worm length (safety bound)

    /** Legacy Biome::* ids (protocol-84 client tints grass/water by them). */
    public const BIOME_OCEAN = 0;
    public const BIOME_PLAINS = 1;
    public const BIOME_DESERT = 2;
    public const BIOME_EXTREME_HILLS = 3;
    public const BIOME_FOREST = 4;
    public const BIOME_TAIGA = 5;
    public const BIOME_ICE_PLAINS = 12;
    public const BIOME_BEACH = 16;

    /** Columns whose surface is below this get filled with water up to it. */
    public const SEA_LEVEL = 62;

    private const SECTION_Y_BLOCKS = 16;
    private const CHUNK_SECTION_COUNT = 16; // 0..15 = y 0..255

    private ?Pool $pool = null;

    public function __construct(
        private readonly ThreadingPort $threadingPort,
        private readonly ?int $workerLimit = null,
    ) {}

    public function generateChunk(int $chunkX, int $chunkZ, GeneratorConfig $config): ChunkData {
        return $this->generateChunks([[$chunkX, $chunkZ]], $config)[0];
    }

    /**
     * @param array<int, array{0: int, 1: int}> $chunks chunk coordinate pairs
     * @return list<ChunkData> one per input, in input order
     */
    public function generateChunks(array $chunks, GeneratorConfig $config): array {
        if (empty($chunks)) {
            return [];
        }
        if ($this->pool === null) {
            // pmmpthread workers inherit the main thread's class table when
            // they start but cannot autoload: requiring autoload.php on a
            // worker is a no-op (workers inherit the included-files table), so
            // the composer loader is never registered there. Force-load every
            // class a task constructs on the MAIN thread now, so the workers
            // always have them regardless of what the boot path happened to
            // load.
            class_exists(ChunkData::class);
            class_exists(NativeAccel::class);
            $this->pool = new Pool(self::workerCount());
        }

        // Submit every chunk up front so the pool runs them in parallel, then
        // wait for all of them - this is where the wall-clock win comes from
        // (N chunks across the worker pool, not N serialized calls).
        /** @var list<ChunkGenResult> $cells */
        $cells = [];
        foreach ($chunks as [$chunkX, $chunkZ]) {
            $out = new ChunkGenResult();
            $cells[] = $out;
            $this->pool->submit(
                new ChunkGenerationTask($chunkX, $chunkZ, $config->generatorType, $config->seed, $out, NativeAccel::isEnabled())
            );
        }

        // Await completion by reaping finished tasks. Reading each result
        // INSIDE the collector callback is the safe pattern (what PocketMine
        // does): the pool hands us a task whose run() has fully returned and
        // that is still referenced by the pool, so touching its result cell
        // there cannot race a worker that is still unwinding (which would
        // otherwise throw "connection to an object which has already been
        // destroyed" and dump core).
        $count = count($chunks);
        /** @var array<int, ChunkData> $data */
        $data = [];
        $deadline = microtime(true) + 30.0;
        while (count($data) < $count) {
            $this->pool->collect(function (ChunkGenerationTask $task) use (&$cells, &$data): bool {
                $out = $task->getOut();
                foreach ($cells as $i => $cell) {
                    if ($cell === $out) {
                        $data[$i] = $this->decodeResult($task->getChunkX(), $task->getChunkZ(), $cell);
                        // Release the cell now that its task is reaped, so a
                        // big batch's memory stays bounded by in-flight work
                        // instead of the whole batch (a 256-chunk batch holds
                        // ~20MB of serialized results alone).
                        unset($cells[$i]);
                        return true;
                    }
                }
                return true; // a leftover task from a previous call: just reap it
            });
            if (count($data) < $count) {
                if (microtime(true) > $deadline) {
                    throw new \RuntimeException('chunk generation timed out');
                }
                usleep(500); // workers still running; poll again
            }
        }

        ksort($data);
        return array_values($data);
    }

    private function decodeResult(int $chunkX, int $chunkZ, ChunkGenResult $out): ChunkData {
        // Called from the collect() callback, where the worker is guaranteed
        // done and the task is still referenced by the pool.
        if (!$out->done) {
            throw new \RuntimeException("chunk generation did not complete for chunk $chunkX,$chunkZ");
        }
        if ($out->error !== null) {
            throw new \RuntimeException("chunk generation failed for chunk $chunkX,$chunkZ: {$out->error}");
        }
        $data = unserialize((string)$out->value);
        if (!$data instanceof ChunkData) {
            throw new \RuntimeException("chunk generation returned malformed data for chunk $chunkX,$chunkZ");
        }
        return $data;
    }

    /**
     * Population pass: trees + vegetation, deterministic in
     * (chunkX, chunkZ, seed). Runs on the main thread after generation and
     * returns the populated chunk.
     */
    public function populateChunk(int $chunkX, int $chunkZ, ChunkData $data, int $seed, string $generatorType = ''): ChunkData {
        if ($generatorType === 'nether') {
            return self::populateNetherChunkPure($chunkX, $chunkZ, $data, $seed);
        }
        if ($generatorType === 'nukkit') {
            return NukkitNormalGenerator::populateChunk($chunkX, $chunkZ, $data, $seed);
        }
        return self::populateChunkPure($chunkX, $chunkZ, $data, $seed);
    }

    /**
     * Pure, deterministic chunk generation - safe to call on a worker thread
     * (no instance state, no object captures). This is the single source of
     * terrain truth shared by the async task and any sync fallback.
     */
    public static function generateChunkPure(int $chunkX, int $chunkZ, string $generatorType, int $seed): ChunkData {
        if ($generatorType === "flat") {
            return self::generateFlatChunk($chunkX, $chunkZ);
        }
        if ($generatorType === "void") {
            return self::generateVoidChunk($chunkX, $chunkZ);
        }
        if ($generatorType === "nether") {
            return self::generateNetherChunk($chunkX, $chunkZ, $seed);
        }
        if ($generatorType === "nukkit") {
            return NukkitNormalGenerator::generateChunk($chunkX, $chunkZ, $seed);
        }

        return self::generateTerrainChunk($chunkX, $chunkZ, $seed);
    }

    public function shutdown(): void {
        if ($this->pool !== null) {
            $this->pool->shutdown();
            $this->pool = null;
        }
    }

    private function workerCount(): int {
        // Empirical scaling on 8-core hardware: chunk generation peaks at 4
        // concurrent workers (3.5x over main-thread); beyond that, dispatch
        // overhead and allocator pressure erode the gain. Allow an explicit
        // override for tuning.
        if ($this->workerLimit !== null) {
            return max(1, $this->workerLimit);
        }
        $nproc = max(1, (int)shell_exec('nproc'));
        return max(1, min(4, $nproc - 2));
    }

    private static function generateFlatChunk(int $chunkX, int $chunkZ): ChunkData {
        $surfaceY = 4; // flat world: 3 stone/dirt, grass on top at y=3

        // Build the 4 sections (y 0..63) with str_repeat runs instead of
        // per-cell writes. Section 0: bedrock row (y=0), stone rows (y=1..3),
        // grass row (y=4), then air. Sections 1..3 are all air.
        $rowBedrock = str_repeat(chr(self::BEDROCK_BLOCK), 256);
        $rowStone = str_repeat(chr(self::STONE_BLOCK), 256);
        $rowGrass = str_repeat(chr(self::GRASS_BLOCK), 256);
        $blocks0 = $rowBedrock
            . $rowStone . $rowStone . $rowStone
            . $rowGrass
            . str_repeat("\x00", 4096 - 5 * 256);
        $air = str_repeat("\x00", 4096);

        $sections = [
            [
                'y' => 0,
                'blocks' => $blocks0,
                'data' => $air,
                'skyLight' => str_repeat("\xff", 2048),
                'blockLight' => str_repeat("\x00", 2048),
            ],
        ];
        for ($sy = 1; $sy <= 3; $sy++) {
            $sections[] = [
                'y' => $sy,
                'blocks' => $air,
                'data' => $air,
                'skyLight' => str_repeat("\xff", 2048),
                'blockLight' => str_repeat("\x00", 2048),
            ];
        }

        $biomes = array_fill(0, 256, self::BIOME_PLAINS); // plains
        $heightmap = array_fill(0, 256, $surfaceY + 1);

        return new ChunkData($chunkX, $chunkZ, $sections, $biomes, $heightmap, [], []);
    }

    private static function generateVoidChunk(int $chunkX, int $chunkZ): ChunkData {
        // Pure-void world (Bukkit-style VoidGen): air everywhere except a
        // small 5x5 platform centered on world (0,0) - stone with a grass
        // top at y 62..64 - so a fresh lobby world has somewhere to stand
        // instead of falling into the void. Everything else is air.
        $air = str_repeat("\x00", 4096);
        $sky = str_repeat("\xff", 2048);
        $dark = str_repeat("\x00", 2048);

        // Platform columns: world x/z within [-2..2] land in this chunk.
        $cols = [];
        for ($bz = 0; $bz < 16; $bz++) {
            $wz = $chunkZ * 16 + $bz;
            for ($bx = 0; $bx < 16; $bx++) {
                $wx = $chunkX * 16 + $bx;
                if ($wx >= -2 && $wx <= 2 && $wz >= -2 && $wz <= 2) {
                    $cols[$bz * 16 + $bx] = true;
                }
            }
        }

        // Emit only the sections that can hold platform blocks (y 48..79);
        // missing sections serialize as air, keeping void chunks tiny. Sky
        // light is derived from the height map by the serializer.
        $row = str_repeat("\x00", 256);
        if ($cols !== []) {
            foreach ($cols as $col => $_) {
                $row[$col] = chr(self::STONE_BLOCK);
            }
        }
        $rowGrass = str_repeat("\x00", 256);
        if ($cols !== []) {
            foreach ($cols as $col => $_) {
                $rowGrass[$col] = chr(self::GRASS_BLOCK);
            }
        }
        // Section 3 (world y 48..63): rows 14/15 (y 62/63) are stone.
        $blocks3 = str_repeat("\x00", 14 * 256) . $row . $row;
        // Section 4 (world y 64..79): row 0 (y 64) is the grass top.
        $blocks4 = $rowGrass . str_repeat("\x00", 15 * 256);
        $sections = [
            ['y' => 3, 'blocks' => $blocks3, 'data' => $air, 'skyLight' => $sky, 'blockLight' => $dark],
            ['y' => 4, 'blocks' => $blocks4, 'data' => $air, 'skyLight' => $sky, 'blockLight' => $dark],
        ];

        // Height map: top non-air Y + 1 (65 on the platform, 0 in the void).
        $heightmap = array_fill(0, 256, 0);
        foreach ($cols as $col => $_) {
            $heightmap[$col] = 65;
        }
        $biomes = array_fill(0, 256, self::BIOME_PLAINS);
        return new ChunkData($chunkX, $chunkZ, $sections, $biomes, $heightmap, [], []);
    }

    /**
     * Nether terrain (14.32): a 128-high hell with a bedrock floor AND
     * ceiling, netherrack mass carved by 3D-ish cave noise, and still lava
     * below y=32 (legacy Nether generator: waterHeight 32, emptyHeight 64).
     * Pure and deterministic in (chunkX, chunkZ, seed); the population pass
     * adds quartz, soul sand, gravel, glowstone clusters and ground fire.
     */
    private static function generateNetherChunk(int $chunkX, int $chunkZ, int $seed): ChunkData {
        $bedrock = chr(self::BEDROCK_BLOCK);
        $netherrack = chr(self::NETHERRACK_BLOCK);
        $lava = chr(self::STILL_LAVA_BLOCK);
        $air = "\x00";

        // Per-column mass height: base 52 with 64-cell hills + 16-cell
        // detail, clamped to [36, 106] so a walkable floor always exists and
        // the bedrock ceiling leaves headroom above.
        $heights = self::netherHeights($chunkX, $chunkZ, $seed);

        // Nether biomes are all "hell" (legacy Biome::HELL).
        $biomes = array_fill(0, 256, 8); // Biome::HELL = 8
        $heightmap = array_fill(0, 256, 0);

        // Precompute the 3D-ish cave noise per y-slice in ONE batched native
        // call per slice (each slice XORs a per-y constant into x before the
        // noise, so it is a full 2D field per y). Fallback recomputes lazily
        // per block when the library is unavailable.
        $caves = self::netherCaveSlices($chunkX, $chunkZ, $seed);

        $sections = [];
        for ($sy = 0; $sy < 8; $sy++) { // y 0..127 (protocol-84 nether height)
            $rows = [];
            for ($r = 0; $r < 16; $r++) {
                $y = $sy * 16 + $r;
                $row = '';
                $slice = $caves[$y] ?? null;
                for ($bz = 0; $bz < 16; $bz++) {
                    for ($bx = 0; $bx < 16; $bx++) {
                        if ($y === 0 || $y === 127) {
                            $row .= $bedrock;
                            continue;
                        }
                        $wx = $chunkX * 16 + $bx;
                        $wz = $chunkZ * 16 + $bz;
                        $h = $heights[$bz * 16 + $bx];
                        if ($y > $h) {
                            $row .= $air;
                            continue;
                        }
                        // 3D-ish cave noise: feeding y into the seed makes each
                        // slice differ while staying deterministic. Below the
                        // lava line (y=32) the noise decides netherrack vs
                        // lava lake - the floor is solid netherrack that opens
                        // into lava pools toward the surface (legacy: lava
                        // below waterHeight with the mass filling the bottom).
                        $cave = $slice[$bz * 16 + $bx] ?? self::smoothNoise($wx ^ (($y * 7919) & 0x7FFFFFFF), $wz, $seed ^ 0x5B4C2A91, 5);
                        if ($y <= 32) {
                            // Deeper = more netherrack (threshold falls), so
                            // lava is a sea near y=32 with islands beneath.
                            $row .= $cave > (40000 - (32 - $y) * 500) ? $netherrack : $lava;
                        } elseif ($cave > 43000 && $y < $h - 2) {
                            $row .= $air; // cavern
                        } else {
                            $row .= $netherrack;
                        }
                    }
                }
                $rows[] = $row;
            }
            $blocks = implode('', $rows);
            if (strlen($blocks) < 4096) {
                $blocks .= str_repeat($air, 4096 - strlen($blocks));
            }
            $sections[] = [
                'y' => $sy,
                'blocks' => $blocks,
                'data' => str_repeat("\x00", 4096),
                // No sky in the nether: the store recalcs block light for
                // lava/glowstone, and the serializer sends dark sky nibbles.
                'skyLight' => str_repeat("\x00", 2048),
                'blockLight' => str_repeat("\x00", 2048),
            ];
        }

        // Heightmap (top solid + 1) for the serializer / light pipeline.
        for ($bz = 0; $bz < 16; $bz++) {
            for ($bx = 0; $bx < 16; $bx++) {
                $h = $heights[$bz * 16 + $bx];
                $top = $h;
                // Find the highest solid (non-air) block in the column; the
                // cave carve above may have opened cells right under the top.
                $solid = $top;
                for ($y = $top; $y >= 0; $y--) {
                    $sy = intdiv($y, 16);
                    $idx = ($y & 15) * 256 + $bz * 16 + $bx;
                    $id = ord($sections[$sy]['blocks'][$idx]);
                    if ($id !== 0 && $id !== self::STILL_LAVA_BLOCK) {
                        $solid = $y;
                        break;
                    }
                }
                $heightmap[$bz * 16 + $bx] = $solid + 1;
            }
        }

        return new ChunkData($chunkX, $chunkZ, $sections, $biomes, $heightmap, [], []);
    }

    /**
     * Nether population (legacy NetherOre/NetherGlowStone/GroundFire/NetherLava
     * tables): quartz ore veins, soul sand + gravel patches, glowstone clusters
     * hung from the ceiling, ground fire on netherrack tops and lava lakes.
     * Deterministic in (chunkX, chunkZ, seed) via the same RNG stream pattern
     * as the overworld population pass.
     */
    public static function populateNetherChunkPure(int $chunkX, int $chunkZ, ChunkData $data, int $seed): ChunkData {
        $sections = $data->sections;
        $rng = self::seedRng($chunkX, $chunkZ, $seed ^ 0x8A5F4C31);

        // --- Quartz ore veins: legacy 20 clusters x 16 size, y 0..128. ---
        for ($i = 0; $i < 20; $i++) {
            $bx = ($rng = self::nextRng($rng)) % 16;
            $bz = ($rng = self::nextRng($rng)) % 16;
            $y = 4 + ($rng = self::nextRng($rng)) % 118;
            for ($n = 0; $n < 16; $n++) {
                $dx = (int)(($rng = self::nextRng($rng)) % 3) - 1;
                $dy = (int)(($rng = self::nextRng($rng)) % 3) - 1;
                $dz = (int)(($rng = self::nextRng($rng)) % 3) - 1;
                $ox = $bx + $dx;
                $oz = $bz + $dz;
                $oy = $y + $dy;
                if ($ox < 0 || $ox > 15 || $oz < 0 || $oz > 15 || $oy < 1 || $oy > 126) {
                    continue;
                }
                if (self::readBlock($sections, $ox, $oy, $oz) === self::NETHERRACK_BLOCK) {
                    $sections = self::writeBlock($sections, $ox, $oy, $oz, self::NETHER_QUARTZ_ORE, false);
                }
            }
        }

        // --- Soul sand + gravel patches: legacy 5 clusters x 64, y 0..128. ---
        foreach ([
            [self::SOUL_SAND_BLOCK, 5],
            [self::GRAVEL_BLOCK, 5],
        ] as [$patchBlock, $clusters]) {
            for ($i = 0; $i < $clusters; $i++) {
                $bx = ($rng = self::nextRng($rng)) % 16;
                $bz = ($rng = self::nextRng($rng)) % 16;
                $y = 4 + ($rng = self::nextRng($rng)) % 118;
                for ($n = 0; $n < 64; $n++) {
                    $dx = (int)(($rng = self::nextRng($rng)) % 3) - 1;
                    $dy = (int)(($rng = self::nextRng($rng)) % 3) - 1;
                    $dz = (int)(($rng = self::nextRng($rng)) % 3) - 1;
                    $ox = $bx + $dx;
                    $oz = $bz + $dz;
                    $oy = $y + $dy;
                    if ($ox < 0 || $ox > 15 || $oz < 0 || $oz > 15 || $oy < 1 || $oy > 126) {
                        continue;
                    }
                    if (self::readBlock($sections, $ox, $oy, $oz) === self::NETHERRACK_BLOCK) {
                        $sections = self::writeBlock($sections, $ox, $oy, $oz, $patchBlock, false);
                    }
                }
            }
        }

        // --- Glowstone clusters hung from the ceiling: legacy OreType
        // (Glowstone, 20 clusters x 10) placed at the highest solid block. ---
        for ($i = 0; $i < 20; $i++) {
            $bx = ($rng = self::nextRng($rng)) % 16;
            $bz = ($rng = self::nextRng($rng)) % 16;
            // Scan down from the bedrock ceiling for the first solid block.
            $ceiling = -1;
            for ($y = 126; $y >= 1; $y--) {
                if (self::readBlock($sections, $bx, $y, $bz) !== 0) {
                    $ceiling = $y;
                    break;
                }
            }
            if ($ceiling < 2) {
                continue;
            }
            $base = $ceiling - 1;
            for ($n = 0; $n < 10; $n++) {
                $dx = (int)(($rng = self::nextRng($rng)) % 3) - 1;
                $dz = (int)(($rng = self::nextRng($rng)) % 3) - 1;
                $dy = (int)(($rng = self::nextRng($rng)) % 3) - 1;
                $ox = $bx + $dx;
                $oz = $bz + $dz;
                $oy = $base + $dy;
                if ($ox < 0 || $ox > 15 || $oz < 0 || $oz > 15 || $oy < 2 || $oy > 125) {
                    continue;
                }
                if (self::readBlock($sections, $ox, $oy, $oz) === 0) {
                    $sections = self::writeBlock($sections, $ox, $oy, $oz, self::GLOWSTONE_BLOCK, false);
                }
            }
        }

        // --- Ground fire on netherrack tops: legacy base 1 + random 1. ---
        $fireCount = 1 + (($rng = self::nextRng($rng)) % 2);
        for ($i = 0; $i < $fireCount; $i++) {
            $bx = ($rng = self::nextRng($rng)) % 16;
            $bz = ($rng = self::nextRng($rng)) % 16;
            $top = -1;
            for ($y = 126; $y >= 1; $y--) {
                $id = self::readBlock($sections, $bx, $y, $bz);
                if ($id === self::NETHERRACK_BLOCK || $id === self::SOUL_SAND_BLOCK) {
                    $top = $y;
                    break;
                }
            }
            if ($top >= 1 && $top < 126 && self::readBlock($sections, $bx, $top + 1, $bz) === 0) {
                $sections = self::writeBlock($sections, $bx, $top + 1, $bz, 51, false); // FIRE
            }
        }

        // --- Lava lakes on the surface: legacy 5% per chunk. ---
        if ((($rng = self::nextRng($rng)) % 100) < 5) {
            $bx = 2 + (($rng = self::nextRng($rng)) % 12);
            $bz = 2 + (($rng = self::nextRng($rng)) % 12);
            $top = -1;
            for ($y = 126; $y >= 1; $y--) {
                $id = self::readBlock($sections, $bx, $y, $bz);
                if ($id === self::NETHERRACK_BLOCK || $id === self::SOUL_SAND_BLOCK || $id === self::GRAVEL_BLOCK) {
                    $top = $y;
                    break;
                }
            }
            if ($top >= 1 && $top < 126 && self::readBlock($sections, $bx, $top + 1, $bz) === 0) {
                $sections = self::writeBlock($sections, $bx, $top + 1, $bz, self::LAVA_BLOCK, false);
            }
        }

        return new ChunkData($data->chunkX, $data->chunkZ, $sections, $data->biomes, $data->heightmap, [], []);
    }

    private static function generateTerrainChunk(int $chunkX, int $chunkZ, int $seed): ChunkData {
        // Per-column height + biome (pure; shared with populateChunkPure so
        // the biome array, the surface profile and the vegetation agree).
        $profiles = [];
        $rawHeights = [];
        $nativeProfiles = self::columnProfilesNative($chunkX, $chunkZ, $seed);
        for ($bz = 0; $bz < 16; $bz++) {
            for ($bx = 0; $bx < 16; $bx++) {
                $worldX = $chunkX * 16 + $bx;
                $worldZ = $chunkZ * 16 + $bz;
                $p = $nativeProfiles[$bz * 16 + $bx] ?? self::columnProfile($worldX, $worldZ, $seed);
                $profiles[] = $p;
                $rawHeights[] = $p['h'];
            }
        }

        // Keep enough sections for the tallest column + tree headroom (+8,
        // the population pass grows trees on top of the terrain) and for sea
        // level - capped at 8 sections (protocol-84 height 0..127). Without
        // the headroom, a tree canopy on a tall column silently dropped into a
        // section that was never allocated.
        $topSectionY = min(7, intdiv(max(max($rawHeights) + 8, self::SEA_LEVEL), 16));

        // Per-column block profiles: bedrock, stone core, then the biome
        // surface (grass/dirt, sand, snow, ocean floor), then water up to sea
        // level for submerged columns. The surface ALWAYS ends at y=h (the
        // column top), and the heightmap is simply the profile length, so the
        // surface and heightmap can never disagree. Built with str_repeat
        // runs - a handful of allocations per column instead of ~37K chr()
        // calls per chunk, which is what lets pool workers run concurrently
        // without serializing on the allocator.
        $bedrock = chr(self::BEDROCK_BLOCK);
        $stone = chr(self::STONE_BLOCK);
        $ground = chr(self::GROUND_BLOCK);
        $grass = chr(self::GRASS_BLOCK);
        $sand = chr(self::SAND_BLOCK);
        $gravel = chr(self::GRAVEL_BLOCK);
        $water = chr(self::WATER_BLOCK);
        $snow = chr(self::SNOW_LAYER_BLOCK);
        $heightmap = [];
        $columnBlocks = [];
        foreach ($profiles as $i => $p) {
            $h = $p['h'];
            $biome = $p['biome'];

            // Stone core: bedrock at y=0, then stone up to y=h-3.
            $coreLen = 1 + max(0, $h - 4);
            $body = $bedrock;
            if ($h > 4) {
                $body .= str_repeat($stone, $h - 4);
            }

            // Surface material, ending exactly at y=h (the column top).
            $n = $h + 1 - $coreLen;
            if ($biome === self::BIOME_OCEAN) {
                // Sea floor: sand on the shallow shelf, gravel in the deeps;
                // no grass underwater.
                $body .= str_repeat($h >= self::SEA_LEVEL - 3 ? $sand : $gravel, $n);
            } elseif ($biome === self::BIOME_BEACH || $biome === self::BIOME_DESERT) {
                $body .= str_repeat($sand, $n);
            } elseif ($biome === self::BIOME_EXTREME_HILLS && $h >= 100) {
                // Rocky peaks: exposed stone.
                $body .= str_repeat($stone, $n);
            } else {
                // Plains / forest / taiga / ice / low hills: dirt + grass cap.
                $body .= $n > 1 ? str_repeat($ground, $n - 1) . $grass : $grass;
            }

            // Snow cap on cold or high columns (a snow layer sits on the cap).
            if (($biome === self::BIOME_ICE_PLAINS || $biome === self::BIOME_EXTREME_HILLS) && $h >= 96) {
                $body .= $snow;
            }

            // Water fills the gap between the surface and sea level only:
            // low valleys become lakes, hilltops stay dry.
            if ($h < self::SEA_LEVEL) {
                $body .= str_repeat($water, self::SEA_LEVEL - $h);
            }
            $columnBlocks[] = $body;
            // Heightmap = top non-air Y + 1 = profile length (the snow layer
            // and the water surface are included automatically).
            $heightmap[$i] = strlen($body);
        }

        // Transpose the per-column profiles into row-major section strings
        // (byte = column height bucket at that y), padding the top with air.
        $sections = [];
        for ($sy = 0; $sy <= $topSectionY; $sy++) {
            $y0 = $sy * 16;
            $rows = [];
            for ($r = 0; $r < 16; $r++) {
                $y = $y0 + $r;
                $row = '';
                foreach ($columnBlocks as $p) {
                    $row .= $p[$y] ?? "\x00";
                }
                $rows[] = $row;
            }
            $blocks = implode('', $rows);
            if (strlen($blocks) < 4096) {
                $blocks .= str_repeat("\x00", 4096 - strlen($blocks));
            }

            $sections[] = [
                'y' => $sy,
                'blocks' => $blocks,
                'data' => str_repeat("\x00", 4096),
                'skyLight' => str_repeat("\xff", 2048),
                'blockLight' => str_repeat("\x00", 2048),
            ];
        }

        // Caves: carve 3D worm caverns into the stone core. The pass is a
        // pure function of (chunkX, chunkZ, seed) - it never touches the
        // surface (see carveCaves), so the heightmap/biomes built above stay
        // valid and the population pass can still place trees on top.
        $sections = self::carveCaves($sections, $profiles, $chunkX, $chunkZ, $seed);

        $biomes = [];
        foreach ($profiles as $p) {
            $biomes[] = $p['biome'];
        }

        return new ChunkData($chunkX, $chunkZ, $sections, $biomes, $heightmap, [], []);
    }

    /**
     * Pure per-column terrain: height + biome, both derived from the same
     * noise so the surface profile, the biome array and the population pass
     * always agree. The spawn plateau biases the continent noise near the
     * origin so the configured spawn is land for ANY seed.
     *
     * @return array{h: int, biome: int}
     */
    /**
     * Native-accelerated columnProfile: all 256 columns in ONE FFI call (a
     * per-column call would pay the FFI boundary 256 times and negate the
     * ~12x win). Returns null when the native library is unavailable so the
     * caller falls back to per-column self::columnProfile().
     *
     * @return array<int, array{h: int, biome: int}>|null 256 profiles, column order
     */
    private static function columnProfilesNative(int $chunkX, int $chunkZ, int $seed): ?array {
        $octaves = NativeAccel::noiseOctaves(
            $chunkX,
            $chunkZ,
            $seed,
            [9, 7, 5, 8, 8],
            [0, 0x27D4EB2F, 0x6D2B79F5, 0x4F1BBCDC, 0x11D8E2A9],
        );
        if ($octaves === null) {
            return null;
        }
        $profiles = [];
        for ($bz = 0; $bz < 16; $bz++) {
            for ($bx = 0; $bx < 16; $bx++) {
                $profiles[] = self::columnProfileFromOctaves(
                    $chunkX * 16 + $bx,
                    $chunkZ * 16 + $bz,
                    $seed,
                    $octaves[$bz * 16 + $bx],
                );
            }
        }
        return $profiles;
    }

    /**
     * @param array<int, int> $octaves [continent(9), hills(7), detail(5), temp(8), humidity(8)]
     * @return array{h: int, biome: int}
     */
    private static function columnProfileFromOctaves(int $x, int $z, int $seed, array $octaves): array {
        [$continent, $hills, $detail, $temp, $humidity] = $octaves;

        // Spawn plateau: within 96 blocks of the origin, raise the continent
        // far above the ocean threshold so the spawn area is land. Beyond it
        // the world falls back to natural continents (oceans, islands).
        $boost = intdiv(max(0, 96 - max(abs($x), abs($z))) * 24000, 96);
        $continent = $continent + $boost > 65535 ? 65535 : $continent + $boost;

        // base is [0, 65535]; the 16384 threshold separates land from ocean
        // (~10-15% water). Land slopes up to ~+50 blocks (mountains), the
        // sea floor down to ~-25 (lakes/oceans).
        $height = 62
            + intdiv(($continent - 16384) * 68, 65536)
            + intdiv(($hills - 32768) * 24, 65536)
            + intdiv(($detail - 32768) * 10, 65536);
        $height = max(2, min(120, $height));

        // Biomes from temperature + humidity (both cell-256, so biomes are
        // big regions, not per-chunk noise).
        $cold = $temp < 24576;
        $hot = $temp > 40960;
        $wet = $humidity > 36000;
        $dry = $humidity < 26214;

        if ($height < self::SEA_LEVEL - 1) {
            $biome = self::BIOME_OCEAN;
        } elseif ($height <= self::SEA_LEVEL + 1) {
            $biome = self::BIOME_BEACH;
        } elseif ($height >= 96) {
            $biome = self::BIOME_EXTREME_HILLS;
        } elseif ($cold) {
            $biome = $wet ? self::BIOME_TAIGA : self::BIOME_ICE_PLAINS;
        } elseif ($hot && $dry) {
            $biome = self::BIOME_DESERT;
        } elseif ($wet) {
            $biome = self::BIOME_FOREST;
        } else {
            $biome = self::BIOME_PLAINS;
        }

        return ['h' => $height, 'biome' => $biome];
    }

    /**
     * Per-column terrain: height + biome, both derived from the same noise
     * so the surface profile, the biome array and the population pass always
     * agree. The spawn plateau biases the continent noise near the origin so
     * the configured spawn is land for ANY seed. Computes the 5 octaves then
     * delegates to columnProfileFromOctaves (shared with the native path).
     *
     * @return array{h: int, biome: int}
     */
    private static function columnProfile(int $x, int $z, int $seed): array {
        return self::columnProfileFromOctaves($x, $z, $seed, [
            self::smoothNoise($x, $z, $seed, 9),              // cell 512 continents
            self::smoothNoise($x, $z, $seed ^ 0x27D4EB2F, 7), // cell 128 hills
            self::smoothNoise($x, $z, $seed ^ 0x6D2B79F5, 5), // cell 32 detail
            self::smoothNoise($x, $z, $seed ^ 0x4F1BBCDC, 8), // cell 256 temperature
            self::smoothNoise($x, $z, $seed ^ 0x11D8E2A9, 8), // cell 256 humidity
        ]);
    }

    /**
     * Deterministic population: oak trees, tall grass, flowers, dead bushes
     * and cacti. Pure in (chunkX, chunkZ, seed); trees are placed only where
     * their whole footprint fits inside the chunk (trunk at x/z in [3..12],
     * canopy radius 2), so no cross-chunk writes are ever needed.
     */
    public static function populateChunkPure(int $chunkX, int $chunkZ, ChunkData $data, int $seed): ChunkData {
        $sections = $data->sections;
        $rng = self::seedRng($chunkX, $chunkZ, $seed);

        // Per-column biome + land surface (top solid block above water).
        $surfaces = [];
        $nativeProfiles = self::columnProfilesNative($chunkX, $chunkZ, $seed);
        for ($bz = 0; $bz < 16; $bz++) {
            for ($bx = 0; $bx < 16; $bx++) {
                $worldX = $chunkX * 16 + $bx;
                $worldZ = $chunkZ * 16 + $bz;
                $p = $nativeProfiles[$bz * 16 + $bx] ?? self::columnProfile($worldX, $worldZ, $seed);
                $surfaceY = -1;
                $surfaceBlock = 0;
                if ($p['biome'] !== self::BIOME_OCEAN) {
                    // Heightmap top = surface+1 for land columns; scan down to
                    // the first solid (non-air) block.
                    $top = ($data->heightmap[$bz * 16 + $bx] ?? 0) - 1;
                    for ($y = max(0, $top); $y >= 1; $y--) {
                        $id = self::readBlock($sections, $bx, $y, $bz);
                        if ($id !== 0 && $id !== self::WATER_BLOCK) {
                            $surfaceY = $y;
                            $surfaceBlock = $id;
                            break;
                        }
                    }
                }
                $surfaces[$bz * 16 + $bx] = ['y' => $surfaceY, 'block' => $surfaceBlock, 'biome' => $p['biome']];
            }
        }

        // Trees: a per-chunk count drawn from the biome mix, then placed at
        // random positions where the full canopy fits in the chunk. Forest
        // chunks get 2-3 trees, mixed grass chunks sparse ones, deserts none.
        $grassCols = 0;
        $forestCols = 0;
        foreach ($surfaces as $col) {
            if ($col['block'] !== self::GRASS_BLOCK && $col['block'] !== self::GROUND_BLOCK) {
                continue;
            }
            $grassCols++;
            if ($col['biome'] === self::BIOME_FOREST) {
                $forestCols++;
            }
        }
        $treeCount = 0;
        if ($grassCols > 0) {
            $roll = ($rng = self::nextRng($rng)) % 10;
            if ($forestCols > 64) {
                $treeCount = 2 + ($roll % 2);
            } elseif ($forestCols > 0 || $grassCols > 80) {
                $treeCount = $roll < 4 ? 1 : 0;
            }
        }
        for ($t = 0; $t < $treeCount; $t++) {
            $tx = 3 + ($rng = self::nextRng($rng)) % 10;
            $tz = 3 + ($rng = self::nextRng($rng)) % 10;
            $col = $surfaces[$tz * 16 + $tx] ?? null;
            if ($col === null || $col['y'] <= 0 || ($col['block'] !== self::GRASS_BLOCK && $col['block'] !== self::GROUND_BLOCK)) {
                continue;
            }
            $baseY = $col['y'];
            $trunk = 4 + ($rng = self::nextRng($rng)) % 3; // 4-6
            $topY = $baseY + $trunk;

            // Trunk.
            for ($y = $baseY + 1; $y <= $topY; $y++) {
                $sections = self::writeBlock($sections, $tx, $y, $tz, self::LOG_BLOCK, true);
            }
            // Canopy: two 5x5 (minus corners) layers, then a 3x3 cap.
            for ($ly = 0; $ly <= 1; $ly++) {
                $y = $topY + $ly;
                for ($dx = -2; $dx <= 2; $dx++) {
                    for ($dz = -2; $dz <= 2; $dz++) {
                        if (abs($dx) === 2 && abs($dz) === 2) {
                            continue; // cut the corners
                        }
                        if ($dx === 0 && $dz === 0 && $ly === 1) {
                            continue; // leave room for the trunk tip
                        }
                        $sections = self::writeBlock($sections, $tx + $dx, $y, $tz + $dz, self::LEAVES_BLOCK, true);
                    }
                }
            }
            $y = $topY + 2;
            for ($dx = -1; $dx <= 1; $dx++) {
                for ($dz = -1; $dz <= 1; $dz++) {
                    $sections = self::writeBlock($sections, $tx + $dx, $y, $tz + $dz, self::LEAVES_BLOCK, true);
                }
            }
        }

        // Vegetation: tall grass + flowers on plains/forest, dead bushes and
        // cacti in the desert.
        for ($i = 0; $i < 8; $i++) {
            $x = ($rng = self::nextRng($rng)) % 16;
            $z = ($rng = self::nextRng($rng)) % 16;
            $col = $surfaces[$z * 16 + $x] ?? null;
            if ($col === null || $col['y'] <= 0) {
                continue;
            }
            $above = self::readBlock($sections, $x, $col['y'] + 1, $z);
            if ($above !== 0) {
                continue; // something already occupies this column
            }
            $id = 0;
            if ($col['biome'] === self::BIOME_DESERT) {
                if ($col['block'] === self::SAND_BLOCK) {
                    // Cacti only when a neighbour is also sand (vanilla rule).
                    if ((($rng = self::nextRng($rng)) % 10) < 2 && $x > 0 && $x < 15 && $z > 0 && $z < 15) {
                        $nbSand = self::readBlock($sections, $x - 1, $col['y'], $z) === self::SAND_BLOCK
                            && self::readBlock($sections, $x + 1, $col['y'], $z) === self::SAND_BLOCK;
                        if ($nbSand) {
                            $sections = self::writeBlock($sections, $x, $col['y'] + 1, $z, self::CACTUS_BLOCK, true);
                        }
                        continue;
                    }
                    $id = self::DEAD_BUSH_BLOCK;
                }
            } elseif ($col['block'] === self::GRASS_BLOCK) {
                $roll = ($rng = self::nextRng($rng)) % 10;
                if ($roll < 6) {
                    $id = self::TALL_GRASS_BLOCK;
                } elseif ($roll < 8) {
                    $id = self::DANDELION_BLOCK;
                } else {
                    $id = self::POPPY_BLOCK;
                }
            }
            if ($id !== 0) {
                $sections = self::writeBlock($sections, $x, $col['y'] + 1, $z, $id, true);
            }
        }

        // Ores: deterministic veins in stone. Mirrors the legacy 0.15 Ore
        // populator table (cluster count x size per chunk, Y window) with the
        // legacy ellipsoid-blob geometry, but chunk-local (every write stays
        // inside this chunk's 16x16 columns) and only ever replacing stone -
        // never air, water, dirt or surface blocks. Drawn AFTER vegetation on
        // the same RNG stream so tree/grass placement is byte-identical.

        // Per-chunk vein budget, seeded from the shared stream (the legacy
        // table: coal any depth, iron <= 64, gold/lapis <= 32, redstone/diamond
        // <= 16). Emerald only spawns in extreme-hills terrain.
        $oreTable = [
            [self::COAL_ORE, 20, 16, 0, 128],
            [self::IRON_ORE, 20, 8, 0, 64],
            [self::REDSTONE_ORE, 8, 7, 0, 16],
            [self::LAPIS_ORE, 1, 6, 0, 32],
            [self::GOLD_ORE, 2, 8, 0, 32],
            [self::DIAMOND_ORE, 1, 7, 0, 16],
        ];
        foreach ($surfaces as $col) {
            if ($col['biome'] === self::BIOME_EXTREME_HILLS) {
                $oreTable[] = [self::EMERALD_ORE, 1, 4, 4, 32];
                break;
            }
        }

        foreach ($oreTable as [$oreId, $clusterCount, $clusterSize, $minY, $maxY]) {
            for ($c = 0; $c < $clusterCount; $c++) {
                $ox = ($rng = self::nextRng($rng)) % 16;
                $oy = $minY + ($rng = self::nextRng($rng)) % ($maxY - $minY + 1);
                $oz = ($rng = self::nextRng($rng)) % 16;

                // The vein anchor must land in stone, and the whole blob must
                // stay underground: skip if the anchor is at/above the local
                // surface of its column or not inside stone.
                $col = $surfaces[$oz * 16 + $ox] ?? null;
                if ($col === null || $col['y'] <= 0) {
                    continue;
                }
                $anchor = self::readBlock($sections, $ox, $oy, $oz);
                if ($anchor !== self::STONE_BLOCK || $oy >= $col['y']) {
                    continue;
                }

                // Legacy ellipsoid blob: two lobes along a random horizontal
                // angle, with a sine-weighted radius so veins read as streaks
                // rather than perfect spheres. Chunk-local: centered on the
                // anchor (no legacy +8 world-coordinate offset) so the blob
                // stays inside the 16x16 columns.
                $angle = (($rng = self::nextRng($rng)) % 62832) / 10000.0; // 0..2pi
                $dx = (int) round(cos($angle) * $clusterSize / 8);
                $dz = (int) round(sin($angle) * $clusterSize / 8);
                $x1 = $ox + $dx;
                $x2 = $ox - $dx;
                $z1 = $oz + $dz;
                $z2 = $oz - $dz;
                $y1 = $oy + ($rng = self::nextRng($rng)) % 3 + 2;
                $y2 = $oy + ($rng = self::nextRng($rng)) % 3 + 2;
                for ($count = 0; $count <= $clusterSize; $count++) {
                    $seedX = $x1 + ($x2 - $x1) * $count / $clusterSize;
                    $seedY = $y1 + ($y2 - $y1) * $count / $clusterSize;
                    $seedZ = $z1 + ($z2 - $z1) * $count / $clusterSize;
                    $size = ((sin($count * (M_PI / $clusterSize)) + 1) * ($rng = self::nextRng($rng)) % 1000 / 1000.0 * $clusterSize / 16 + 1) / 2;
                    $startX = (int) ($seedX - $size);
                    $startY = (int) ($seedY - $size);
                    $startZ = (int) ($seedZ - $size);
                    $endX = (int) ($seedX + $size);
                    $endY = (int) ($seedY + $size);
                    $endZ = (int) ($seedZ + $size);
                    for ($bx = $startX; $bx <= $endX; $bx++) {
                        $sizeX = ($bx + 0.5 - $seedX) / $size;
                        $sizeX *= $sizeX;
                        if ($sizeX >= 1) {
                            continue;
                        }
                        for ($by = $startY; $by <= $endY; $by++) {
                            // Keep the blob inside the ore's Y window (the
                            // legacy lobe offsets can push +2..4 past the
                            // anchor, so clamp instead of overshooting).
                            if ($by <= 0 || $by > $maxY) {
                                continue;
                            }
                            $sizeY = ($by + 0.5 - $seedY) / $size;
                            $sizeY *= $sizeY;
                            if ($sizeX + $sizeY >= 1) {
                                continue;
                            }
                            for ($bz = $startZ; $bz <= $endZ; $bz++) {
                                // Chunk-local: skip anything outside the 16x16
                                // column footprint (no cross-chunk writes).
                                if ($bx < 0 || $bx > 15 || $bz < 0 || $bz > 15) {
                                    continue;
                                }
                                $sizeZ = ($bz + 0.5 - $seedZ) / $size;
                                $sizeZ *= $sizeZ;
                                if ($sizeX + $sizeY + $sizeZ >= 1) {
                                    continue;
                                }
                                // Only replace stone (never air, water, or
                                // surface blocks).
                                $target = self::readBlock($sections, $bx, $by, $bz);
                                if ($target !== self::STONE_BLOCK) {
                                    continue;
                                }
                                // Never leave an ore floating over a carved
                                // cavity: the block below must also be stone.
                                if ($by > 1 && self::readBlock($sections, $bx, $by - 1, $bz) !== self::STONE_BLOCK) {
                                    continue;
                                }
                                $sections = self::writeBlock($sections, $bx, $by, $bz, $oreId, false);
                            }
                        }
                    }
                }
            }
        }

        return new ChunkData($data->chunkX, $data->chunkZ, $sections, $data->biomes, $data->heightmap, $data->entities, $data->tileEntities);
    }

    /**
     * Carve 3D worm caverns into the stone core. Pure in (chunkX, chunkZ,
     * seed): cave systems are anchored to a 64-block world grid, so adjacent
     * chunks carve the SAME global path and caverns connect across borders
     * even though each chunk is generated independently.
     *
     * Safety rules: only stone is ever replaced (never air, water, dirt,
     * sand or surface blocks); the top 2 blocks of every column are never
     * touched (no holes in the ground, heightmap stays valid); y=0 bedrock is
     * never touched; every write stays inside the chunk's 16x16 columns;
     * carved cells below y=10 become lava pools, and carved cells under the
     * ocean floor fill with water (biome ocean, below sea level).
     *
     * @param array $sections section strings (returned, mutated copy)
     * @param array $profiles per-column ['h' => int, 'biome' => int]
     * @return array updated sections
     */
    private static function carveCaves(array $sections, array $profiles, int $chunkX, int $chunkZ, int $seed): array {
        $worldX0 = $chunkX * 16;
        $worldZ0 = $chunkZ * 16;

        // Grid cells that could host a cave system intersecting this chunk.
        $gx0 = intdiv($worldX0 - self::CAVE_EXTENT, self::CAVE_GRID);
        $gx1 = intdiv($worldX0 + 15 + self::CAVE_EXTENT, self::CAVE_GRID);
        $gz0 = intdiv($worldZ0 - self::CAVE_EXTENT, self::CAVE_GRID);
        $gz1 = intdiv($worldZ0 + 15 + self::CAVE_EXTENT, self::CAVE_GRID);

        for ($gz = $gz0; $gz <= $gz1; $gz++) {
            for ($gx = $gx0; $gx <= $gx1; $gx++) {
                $cellSeed = self::seedRng($gx, $gz, $seed ^ 0xCA7EC0DE);
                $cellSeed = self::nextRng($cellSeed);
                if ($cellSeed % 4 === 0) {
                    continue; // ~3/4 of cells host a cave system
                }

                $cellX = $gx * self::CAVE_GRID + self::CAVE_GRID / 2; // cell center
                $cellZ = $gz * self::CAVE_GRID + self::CAVE_GRID / 2;
                $worms = 2 + ($cellSeed % 3); // 2-4 worms per system
                for ($w = 0; $w < $worms; $w++) {
                    $rng = self::seedRng($gx * 7 + $w, $gz * 13 + $w, $seed ^ 0xDEADBEEF);
                    $sections = self::carveWorm($sections, $profiles, $cellX, $cellZ, $worldX0, $worldZ0, $rng);
                }
            }
        }
        return $sections;
    }

    /** Carve one wandering worm tube from a cell center. */
    private static function carveWorm(array $sections, array $profiles, float $cellX, float $cellZ, int $worldX0, int $worldZ0, int $rng): array {
        $x = $cellX + (($rng = self::nextRng($rng)) % 2400) / 100.0 - 12.0;
        $z = $cellZ + (($rng = self::nextRng($rng)) % 2400) / 100.0 - 12.0;
        $y = 6.0 + (($rng = self::nextRng($rng)) % 4000) / 100.0; // 6..46 start
        $yaw = (($rng = self::nextRng($rng)) % 62832) / 10000.0;   // 0..2pi
        $pitch = (($rng = self::nextRng($rng)) % 2000) / 10000.0 - 0.1; // ~level
        $radius = 1.5 + (($rng = self::nextRng($rng)) % 30) / 20.0;     // 1.5..3.0
        $segments = 14 + (($rng = self::nextRng($rng)) % self::CAVE_MAX_SEGMENTS);

        $step = 1.4;
        for ($i = 0; $i < $segments; $i++) {
            // Cheap cull: only carve spheres near this chunk's footprint.
            if ($x >= $worldX0 - $radius - 1 && $x <= $worldX0 + 16 + $radius
                && $z >= $worldZ0 - $radius - 1 && $z <= $worldZ0 + 16 + $radius) {
                $sections = self::carveSphere($sections, $profiles, $x, $y, $z, $radius, $worldX0, $worldZ0);
            }
            // Gentle yaw wander + slow upward drift so worms snake and climb.
            $yaw += (($rng = self::nextRng($rng)) % 6000) / 10000.0 - 0.3; // -0.3..0.3 rad
            $pitch = min(0.4, $pitch + 0.005);
            $x += cos($yaw) * cos($pitch) * $step;
            $y += sin($pitch) * $step;
            $z += sin($yaw) * cos($pitch) * $step;
            if ($y < 3.0 || $y > 96.0) {
                break;
            }
        }
        return $sections;
    }

    /** Carve one sphere of a worm tube, clamped to the chunk's columns. */
    private static function carveSphere(array $sections, array $profiles, float $cx, float $cy, float $cz, float $r, int $worldX0, int $worldZ0): array {
        $r2 = $r * $r;
        // Sphere bounding box clamped to the 16x16 column footprint.
        $x0 = max(0, (int) floor($cx - $r) - $worldX0);
        $x1 = min(15, (int) ceil($cx + $r) - $worldX0);
        $z0 = max(0, (int) floor($cz - $r) - $worldZ0);
        $z1 = min(15, (int) ceil($cz + $r) - $worldZ0);
        $y0 = max(1, (int) floor($cy - $r));
        $y1 = min(120, (int) ceil($cy + $r));

        for ($bx = $x0; $bx <= $x1; $bx++) {
            $dx = $bx + $worldX0 + 0.5 - $cx;
            $dx *= $dx;
            for ($bz = $z0; $bz <= $z1; $bz++) {
                $dz = $bz + $worldZ0 + 0.5 - $cz;
                $dz *= $dz;
                $col = $profiles[$bz * 16 + $bx];
                $surfaceLimit = $col['h'] - 2; // keep the top 2 blocks intact
                for ($by = $y0; $by <= $y1; $by++) {
                    if ($by >= $surfaceLimit) {
                        break;
                    }
                    $dy = $by + 0.5 - $cy;
                    $dy *= $dy;
                    if ($dx + $dy + $dz >= $r2) {
                        continue;
                    }
                    if (self::readBlock($sections, $bx, $by, $bz) !== self::STONE_BLOCK) {
                        continue; // only carve stone
                    }
                    $replacement = 0; // air
                    if ($by <= 10) {
                        $replacement = self::STILL_LAVA_BLOCK; // lava pools below y=10
                    } elseif ($col['biome'] === self::BIOME_OCEAN && $by < self::SEA_LEVEL) {
                        $replacement = self::WATER_BLOCK; // flooded caves under the sea
                    }
                    $sections = self::writeBlock($sections, $bx, $by, $bz, $replacement, false);
                }
            }
        }
        return $sections;
    }

    /** Read a block id from the section strings. */
    private static function readBlock(array $sections, int $x, int $y, int $z): int {
        $sy = intdiv($y, 16);
        $sec = $sections[$sy] ?? null;
        if ($sec === null) {
            return 0;
        }
        $idx = ($y & 15) * 256 + $z * 16 + $x;
        return ord($sec['blocks'][$idx]);
    }

    /** Set a block (onlyIfAir skips non-air cells), returning updated sections. */
    private static function writeBlock(array $sections, int $x, int $y, int $z, int $id, bool $onlyIfAir): array {
        $sy = intdiv($y, 16);
        $sec = $sections[$sy] ?? null;
        if ($sec === null) {
            return $sections;
        }
        $idx = ($y & 15) * 256 + $z * 16 + $x;
        $blocks = $sec['blocks'];
        if ($onlyIfAir && $blocks[$idx] !== "\x00") {
            return $sections;
        }
        $blocks[$idx] = chr($id);
        $sec['blocks'] = $blocks;
        $sections[$sy] = $sec;
        return $sections;
    }

    /** Deterministic per-chunk RNG seed. */
    private static function seedRng(int $chunkX, int $chunkZ, int $seed): int {
        $n = ($chunkX * 374761393) ^ ($chunkZ * 668265263) ^ ($seed & 0x7FFFFFFF);
        $n = (($n ^ ($n >> 13)) * 1274126177) & 0x7FFFFFFF;
        return $n ^ ($n >> 16);
    }

    private static function nextRng(int $rng): int {
        $rng ^= ($rng << 13) & 0x7FFFFFFF;
        $rng ^= $rng >> 17;
        $rng ^= ($rng << 5) & 0x7FFFFFFF;
        return $rng & 0x7FFFFFFF;
    }

    /**
     * Precompute the nether cave noise field for all 128 y-slices: one
     * batched native call per slice (each slice XORs a per-y constant into x,
     * so it is a full 2D field). Returns null when the native library is
     * unavailable; the caller then falls back to per-block smoothNoise.
     *
     * @return array<int, array<int, int>>|null [y => [column => noise]]
     */
    private static function netherCaveSlices(int $chunkX, int $chunkZ, int $seed): ?array {
        if (!NativeAccel::available()) {
            return null;
        }
        $slices = [];
        for ($y = 0; $y < 128; $y++) {
            $values = NativeAccel::noiseColumnsXor($chunkX, $chunkZ, $y * 7919, $seed ^ 0x5B4C2A91, 5);
            if ($values === null) {
                return null;
            }
            $slices[$y] = $values;
        }
        return $slices;
    }

    /**
     * Per-column nether mass heights: base 52 with 64-cell hills (shift 6,
     * xor 0x6E5C2F) + 16-cell detail (shift 4, xor 0x3D1B7A), clamped to
     * [36, 106]. Uses the native batched noise path (all 256 columns in one
     * FFI call) with a pure-PHP fallback. Returns column-indexed heights.
     *
     * @return array<int, int>
     */
    private static function netherHeights(int $chunkX, int $chunkZ, int $seed): array {
        $octaves = NativeAccel::noiseOctaves($chunkX, $chunkZ, $seed, [6, 4], [0x6E5C2F, 0x3D1B7A]);
        if ($octaves === null) {
            $heights = [];
            for ($bz = 0; $bz < 16; $bz++) {
                for ($bx = 0; $bx < 16; $bx++) {
                    $wx = $chunkX * 16 + $bx;
                    $wz = $chunkZ * 16 + $bz;
                    $h = 52
                        + intdiv((self::smoothNoise($wx, $wz, $seed ^ 0x6E5C2F, 6) - 32768) * 40, 65536)
                        + intdiv((self::smoothNoise($wx, $wz, $seed ^ 0x3D1B7A, 4) - 32768) * 16, 65536);
                    $heights[$bz * 16 + $bx] = max(36, min(106, $h));
                }
            }
            return $heights;
        }
        $heights = [];
        for ($c = 0; $c < 256; $c++) {
            $h = 52
                + intdiv(($octaves[$c][0] - 32768) * 40, 65536)
                + intdiv(($octaves[$c][1] - 32768) * 16, 65536);
            $heights[$c] = max(36, min(106, $h));
        }
        return $heights;
    }

    /**
     * Smooth value noise: hash the four corners of the cell containing
     * (x, z), then bilinear-interpolate with a smoothstep weight in each
     * axis. Returns [0, 65535].
     *
     * CRITICAL: every intermediate stays within 64-bit int range. Masking to
     * 31 bits before each multiply keeps the math int-only (floats here are
     * pathologically slow, and ~20x worse with several pool workers at once).
     */
    private static function smoothNoise(int $x, int $z, int $seed, int $shift): int {
        $cell = 1 << $shift;
        // Arithmetic right shift = floor division for negative coordinates;
        // the mask yields the matching non-negative remainder (x = cell*g + f
        // holds for negatives too, unlike intdiv which truncates toward 0).
        $gx = $x >> $shift;
        $gz = $z >> $shift;
        $fx = $x & ($cell - 1);
        $fz = $z & ($cell - 1);

        $v00 = self::noise2D($gx, $gz, $seed);
        $v10 = self::noise2D($gx + 1, $gz, $seed);
        $v01 = self::noise2D($gx, $gz + 1, $seed);
        $v11 = self::noise2D($gx + 1, $gz + 1, $seed);

        // Smoothstep weight in Q16 fixed point: w = 3u^2 - 2u^3, u = f/cell.
        $u = intdiv($fx * 65536, $cell);
        $u2 = intdiv($u * $u, 65536);
        $u3 = intdiv($u2 * $u, 65536);
        $tx = 3 * $u2 - 2 * $u3;

        $u = intdiv($fz * 65536, $cell);
        $u2 = intdiv($u * $u, 65536);
        $u3 = intdiv($u2 * $u, 65536);
        $tz = 3 * $u2 - 2 * $u3;

        $top = $v00 + intdiv(($v10 - $v00) * $tx, 65536);
        $bottom = $v01 + intdiv(($v11 - $v01) * $tx, 65536);
        return $top + intdiv(($bottom - $top) * $tz, 65536);
    }

    /**
     * Deterministic integer hash -> [0, 65535]. The seed is masked to 31
     * bits so full-range seeds and far-from-origin coordinates cannot
     * overflow 64-bit ints (PHP would silently widen to float).
     */
    private static function noise2D(int $x, int $z, int $seed): int {
        $seed &= 0x7FFFFFFF;
        $n = ($x * 374761393) ^ ($z * 668265263) ^ ($seed * 1103515245);
        $n &= 0x7FFFFFFF;
        $n = ($n ^ ($n >> 13)) * 1274126177; // <= 2^31 * 1.27e9 = 2.7e18, fits
        $n &= 0x7FFFFFFF;
        $n ^= $n >> 16;
        return $n & 0xFFFF;
    }
}

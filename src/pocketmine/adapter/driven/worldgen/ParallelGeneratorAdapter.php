<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\worldgen;

use pocketmine\port\driven\ChunkData;
use pocketmine\port\driven\GeneratorConfig;
use pocketmine\port\driven\LightData;
use pocketmine\port\driven\ThreadingPort;
use pocketmine\port\driven\WorldGenPort;
use pmmp\thread\Pool;
use function array_fill;
use function chr;
use function intdiv;
use function max;
use function microtime;
use function min;
use function serialize;
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
                new ChunkGenerationTask($chunkX, $chunkZ, $config->generatorType, $config->seed, $out)
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
    public function populateChunk(int $chunkX, int $chunkZ, ChunkData $data, int $seed): ChunkData {
        return self::populateChunkPure($chunkX, $chunkZ, $data, $seed);
    }

    public function calculateLight(int $chunkX, int $chunkZ, ChunkData $data): LightData {
        // Sky light from the top of the world down; no block light by default.
        $skyLight = [];
        $blockLight = [];

        foreach ($data->sections as $section) {
            $skyLight[] = str_repeat("\xff", 2048);
            $blockLight[] = str_repeat("\x00", 2048);
        }

        return new LightData($skyLight, $blockLight);
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

    private static function generateTerrainChunk(int $chunkX, int $chunkZ, int $seed): ChunkData {
        // Per-column height + biome (pure; shared with populateChunkPure so
        // the biome array, the surface profile and the vegetation agree).
        $profiles = [];
        $rawHeights = [];
        for ($bz = 0; $bz < 16; $bz++) {
            for ($bx = 0; $bx < 16; $bx++) {
                $worldX = $chunkX * 16 + $bx;
                $worldZ = $chunkZ * 16 + $bz;
                $p = self::columnProfile($worldX, $worldZ, $seed);
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
    private static function columnProfile(int $x, int $z, int $seed): array {
        // Big feature cells: 512-block continents, 128-block hills, 32-block
        // detail - 4x the scale of the old generator, so the world reads as
        // large instead of patchy.
        $continent = self::smoothNoise($x, $z, $seed, 9);              // cell 512
        $hills = self::smoothNoise($x, $z, $seed ^ 0x27D4EB2F, 7);     // cell 128
        $detail = self::smoothNoise($x, $z, $seed ^ 0x6D2B79F5, 5);    // cell 32

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
        $temp = self::smoothNoise($x, $z, $seed ^ 0x4F1BBCDC, 8);      // cell 256
        $humidity = self::smoothNoise($x, $z, $seed ^ 0x11D8E2A9, 8);  // cell 256
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
        for ($bz = 0; $bz < 16; $bz++) {
            for ($bx = 0; $bx < 16; $bx++) {
                $worldX = $chunkX * 16 + $bx;
                $worldZ = $chunkZ * 16 + $bz;
                $p = self::columnProfile($worldX, $worldZ, $seed);
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

        return new ChunkData($data->chunkX, $data->chunkZ, $sections, $data->biomes, $data->heightmap, $data->entities, $data->tileEntities);
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

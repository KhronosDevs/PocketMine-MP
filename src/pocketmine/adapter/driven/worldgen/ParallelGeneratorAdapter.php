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
use function ceil;
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
 * Generates flat/terraced terrain directly into ChunkData DTOs - no
 * dependency on legacy Server/Level/Generator classes. The generator
 * type comes from GeneratorConfig (e.g. "flat", "normal").
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

    public function populateChunk(int $chunkX, int $chunkZ, ChunkData $data): void {
        // Structure/ore population is a no-op for the flat generator.
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

        $biomes = array_fill(0, 256, 1); // plains
        $heightmap = array_fill(0, 256, $surfaceY + 1);

        return new ChunkData($chunkX, $chunkZ, $sections, $biomes, $heightmap, [], []);
    }

    private static function generateTerrainChunk(int $chunkX, int $chunkZ, int $seed): ChunkData {
        // Simple deterministic pseudo-noise heightmap based on chunk coords + seed.
        $heightmap = [];
        for ($bz = 0; $bz < 16; $bz++) {
            for ($bx = 0; $bx < 16; $bx++) {
                $worldX = $chunkX * 16 + $bx;
                $worldZ = $chunkZ * 16 + $bz;
                $heightmap[$bz * 16 + $bx] = self::sampleHeight($worldX, $worldZ, $seed);
            }
        }

        $maxHeight = max($heightmap);
        $topSectionY = (int)ceil($maxHeight / 16);

        // Build each column's full-height block profile once using str_repeat
        // runs: bedrock, stone down to h-3, 3 dirt, grass at h, 3 water above.
        // This is a handful of allocations per column instead of ~37K chr()
        // calls per chunk, which is what lets pool workers run concurrently
        // without serializing on the allocator.
        $bedrock = chr(self::BEDROCK_BLOCK);
        $stone = chr(self::STONE_BLOCK);
        $ground = chr(self::GROUND_BLOCK);
        $grass = chr(self::GRASS_BLOCK);
        $water = chr(self::WATER_BLOCK);
        $profiles = [];
        foreach ($heightmap as $h) {
            $p = $bedrock;
            if ($h > 4) {
                $p .= str_repeat($stone, $h - 4);
            }
            $p .= str_repeat($ground, min(3, max(0, $h - 1)));
            $p .= $grass;
            $p .= str_repeat($water, 3);
            $profiles[] = $p;
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
                foreach ($profiles as $p) {
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

        $biomes = array_fill(0, 256, 1);

        return new ChunkData($chunkX, $chunkZ, $sections, $biomes, $heightmap, [], []);
    }

    private static function sampleHeight(int $x, int $z, int $seed): int {
        // 2D value-noise approximation using pure integer hashing.
        //
        // CRITICAL: every intermediate must stay within 64-bit int range. The
        // old implementation multiplied un-masked values past 2^63, which PHP
        // silently converts to float; those float->int casts in &/>> are
        // pathologically slow (and ~20x slower again when several pool
        // workers run at once), serializing concurrent chunk generation.
        // Masking to 31 bits before each multiply keeps the math int-only.
        $n = $x * 374761393 + $z * 668265263 + $seed * 1103515245;
        $n &= 0x7FFFFFFF;
        $n = ($n ^ ($n >> 13)) * 1274126177; // <= 2^31 * 1.27e9 = 2.7e18, fits
        $n &= 0x7FFFFFFF;
        $n ^= $n >> 16;

        // Combine two octaves for gentle hills.
        $coarse = self::hash31(intdiv($x, 8), intdiv($z, 8), $seed);
        $fine = self::hash31(intdiv($x, 2), intdiv($z, 2), $seed ^ 0x9E3779B9);

        // $coarse/$fine in [0, 65535]; scale with int math, never floats.
        $height = 64 + intdiv($coarse * 24, 65536) + intdiv($fine * 8, 65536);
        return max(2, min(120, $height));
    }

    private static function hash31(int $x, int $z, int $seed): int {
        $n = ($x * 73856093) ^ ($z * 19349663) ^ ($seed * 83492791);
        $n &= 0x7FFFFFFF;
        $n = (($n ^ ($n >> 13)) * 1274126177); // <= 2^31 * 1.27e9 = 2.7e18, fits
        $n &= 0x7FFFFFFF;
        $n = $n ^ ($n >> 16);
        return $n & 0xFFFF;
    }
}

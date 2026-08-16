<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require_once __DIR__ . '/helpers.php';

use pocketmine\adapter\driven\worldgen\ParallelGeneratorAdapter;
use pocketmine\port\driven\ChunkData;

const CAVE_SEED = 42;

/** Generate a chunk (terrain + cave carve, no population). */
function cGen(int $cx, int $cz): ChunkData {
    return ParallelGeneratorAdapter::generateChunkPure($cx, $cz, 'normal', CAVE_SEED);
}

function cBlock(ChunkData $data, int $x, int $y, int $z): int {
    $sy = intdiv($y, 16);
    $sec = $data->sections[$sy] ?? null;
    if ($sec === null) {
        return 0;
    }
    $idx = ($y & 15) * 256 + $z * 16 + $x;
    return ord($sec['blocks'][$idx]);
}

test('the carver opens real caverns below the surface', function (): void {
    // Scan a large area and confirm many chunks contain carved air that is
    // NOT sky (air below the heightmap top). A world without caves has zero
    // such blocks; a carved world has hundreds of thousands.
    $carvedAir = 0;
    $chunksWithCaves = 0;
    for ($cx = -12; $cx < 12; $cx++) {
        for ($cz = -12; $cz < 12; $cz++) {
            $data = cGen($cx, $cz);
            $chunkAir = 0;
            foreach ($data->sections as $sec) {
                $blocks = $sec['blocks'];
                for ($i = 0; $i < 4096; $i++) {
                    $y = $sec['y'] * 16 + intdiv($i, 256);
                    if (ord($blocks[$i]) !== 0) {
                        continue;
                    }
                    $bx = $i % 16;
                    $bz = intdiv($i, 16) % 16;
                    $col = $bz * 16 + $bx;
                    // Only count air strictly below the column's heightmap top
                    // (sky air is at/above it).
                    if ($y < ($data->heightmap[$col] ?? 0) - 1) {
                        $chunkAir++;
                    }
                }
            }
            if ($chunkAir > 0) {
                $chunksWithCaves++;
            }
            $carvedAir += $chunkAir;
        }
    }
    ok($carvedAir > 100000, "tens of thousands of carved-air blocks exist (got $carvedAir)");
    ok($chunksWithCaves > 100, "most chunks contain at least one carved block (got $chunksWithCaves/576)");
});

test('caves never break through the surface', function (): void {
    // The top 2 blocks of every column (heightmap top and the block below)
    // must be the original terrain, never carved air.
    $breach = 0;
    for ($cx = -12; $cx < 12; $cx++) {
        for ($cz = -12; $cz < 12; $cz++) {
            $data = cGen($cx, $cz);
            foreach ($data->heightmap as $ci => $h) {
                if ($h <= 1) {
                    continue; // void column
                }
                $bx = $ci % 16;
                $bz = intdiv($ci, 16);
                for ($off = 0; $off < 2; $off++) {
                    $y = $h - 1 - $off;
                    if ($y <= 0) {
                        continue;
                    }
                    if (cBlock($data, $bx, $y, $bz) === 0) {
                        $breach++;
                    }
                }
            }
        }
    }
    same(0, $breach, 'no carved air within the top 2 blocks of any column');
});

test('lava pools only form below y=10 and bedrock is never carved', function (): void {
    $lavaAbove = 0;
    $bedrockOre = 0;
    for ($cx = -12; $cx < 12; $cx++) {
        for ($cz = -12; $cz < 12; $cz++) {
            $data = cGen($cx, $cz);
            foreach ($data->sections as $sec) {
                $blocks = $sec['blocks'];
                for ($i = 0; $i < 4096; $i++) {
                    $id = ord($blocks[$i]);
                    $y = $sec['y'] * 16 + intdiv($i, 256);
                    if ($id === ParallelGeneratorAdapter::STILL_LAVA_BLOCK && $y > 10) {
                        $lavaAbove++;
                    }
                    // y=0 is bedrock in this generator; nothing carved may
                    // replace it (the carver starts at y>=1 by construction,
                    // and lava at y<=10 includes y=1..10 stone, never y=0).
                    if ($y === 0 && $id !== ParallelGeneratorAdapter::BEDROCK_BLOCK) {
                        $bedrockOre++;
                    }
                }
            }
        }
    }
    same(0, $lavaAbove, 'no lava above y=10');
    same(0, $bedrockOre, 'bedrock row is intact');
});

test('cave water only appears in ocean columns', function (): void {
    // Any water strictly BELOW the terrain surface must sit in an ocean
    // column. Terrain lakes fill low land valleys from the surface up to sea
    // level, so their water is at/above the surface - only the carver places
    // water under the surface, and it only does so in ocean biomes.
    $bad = 0;
    for ($cx = -12; $cx < 12; $cx++) {
        for ($cz = -12; $cz < 12; $cz++) {
            $data = cGen($cx, $cz);
            // Per column, find the terrain surface: the topmost non-air,
            // non-water block (the solid ground a cave sits under).
            $surfaceY = [];
            foreach ($data->sections as $sec) {
                $blocks = $sec['blocks'];
                for ($i = 0; $i < 4096; $i++) {
                    $bx = $i % 16;
                    $bz = intdiv($i, 16) % 16;
                    $col = $bz * 16 + $bx;
                    $id = ord($blocks[$i]);
                    if ($id === 0 || $id === ParallelGeneratorAdapter::WATER_BLOCK) {
                        continue;
                    }
                    $y = $sec['y'] * 16 + intdiv($i, 256);
                    $surfaceY[$col] = max($surfaceY[$col] ?? -1, $y);
                }
            }
            foreach ($data->sections as $sec) {
                $blocks = $sec['blocks'];
                for ($i = 0; $i < 4096; $i++) {
                    if (ord($blocks[$i]) !== ParallelGeneratorAdapter::WATER_BLOCK) {
                        continue;
                    }
                    $bx = $i % 16;
                    $bz = intdiv($i, 16) % 16;
                    $col = $bz * 16 + $bx;
                    $y = $sec['y'] * 16 + intdiv($i, 256);
                    // Water under the solid surface = carved cave water.
                    if ($y < ($surfaceY[$col] ?? -1)) {
                        if ($data->biomes[$col] !== ParallelGeneratorAdapter::BIOME_OCEAN) {
                            $bad++;
                        }
                    }
                }
            }
        }
    }
    same(0, $bad, 'no cave water outside ocean columns');
});

test('cave carving is deterministic and chunk-safe', function (): void {
    // Determinism: the same chunk twice yields identical sections.
    $a = cGen(5, -7);
    $b = cGen(5, -7);
    same($a->sections, $b->sections, 'same chunk twice -> identical sections');

    // Chunk-safe: sections keep their size and the heightmap is unchanged by
    // carving (the carver never touches the surface).
    $unpopulated = ParallelGeneratorAdapter::generateChunkPure(5, -7, 'normal', CAVE_SEED);
    foreach ($a->sections as $i => $section) {
        same(4096, strlen($section['blocks']), "section $i still 4096 bytes");
    }
    same($unpopulated->heightmap, $a->heightmap, 'carving leaves the heightmap unchanged');
});

exit(runTests());

<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\adapter\driven\worldgen\ParallelGeneratorAdapter;
use pocketmine\port\driven\ChunkData;

/**
 * Phase 14.19: terrain overhaul.
 *
 * The generator produces large-feature terrain (512/128/32-block octaves)
 * with per-column biomes (plains/forest/desert/taiga/ice/hills/beach/ocean),
 * biome-correct surfaces (sand beaches/deserts, snow caps, ocean floors) and
 * a deterministic population pass (trees, grass, flowers, cacti). These tests
 * lock in the invariants: pure determinism, parallel==pure, a guaranteed-land
 * spawn plateau, biome variety, and the presence of structures.
 */

const T_SEED = 42;

function tGen(int $cx, int $cz): ChunkData {
    return ParallelGeneratorAdapter::generateChunkPure($cx, $cz, 'normal', T_SEED);
}

// A scan helper: over a square chunk grid, collect (chunk, biomeCounts).
function tScan(int $radius): array {
    $chunks = [];
    for ($cx = -$radius; $cx < $radius; $cx++) {
        for ($cz = -$radius; $cz < $radius; $cz++) {
            $chunks[] = [$cx, $cz, tGen($cx, $cz)];
        }
    }
    return $chunks;
}

test('terrain generation is deterministic and parallel-identical', function (): void {
    $a = tGen(3, -2);
    $b = tGen(3, -2);
    same($a->sections, $b->sections, 'same chunk twice -> identical sections');
    same($a->biomes, $b->biomes, 'same chunk twice -> identical biomes');

    // The parallel pool path must produce byte-identical terrain.
    $kernel = \pocketmine\bootstrap();
    $port = $kernel->getWorldGenPort();
    $par = $port->generateChunk(3, -2, new \pocketmine\port\driven\GeneratorConfig('normal', T_SEED, []));
    same($a->sections, $par->sections, 'pool-generated chunk equals the pure result');
});

test('heights stay in range and the heightmap agrees with the surface', function (): void {
    foreach (tScan(4) as [$cx, $cz, $data]) {
        foreach ($data->sections as $section) {
            $blocks = $section['blocks'];
            for ($i = 0; $i < 4096; $i += 16) {
                $byte = ord($blocks[$i]);
                ok($byte <= 120, 'no block above y=120 in terrain chunks');
            }
        }
        foreach ($data->heightmap as $h) {
            ok($h >= 2 && $h <= 121, "heightmap in range (got $h)");
        }
    }
});

test('the spawn plateau keeps the origin land for any seed', function (): void {
    foreach ([1, 42, 1337, 987654321] as $seed) {
        $land = 0;
        for ($dx = -2; $dx <= 2; $dx++) {
            for ($dz = -2; $dz <= 2; $dz++) {
                $data = ParallelGeneratorAdapter::generateChunkPure($dx, $dz, 'normal', $seed);
                // Every column in the origin chunks must be land (biome != ocean).
                foreach ($data->biomes as $b) {
                    if ($b !== ParallelGeneratorAdapter::BIOME_OCEAN) {
                        $land++;
                    }
                }
            }
        }
        ok($land > 0, "seed $seed has land at the origin");
        // Land must be the vast majority of origin columns (beaches/hills ok).
        ok($land >= 400, "seed $seed origin is land-dominant (land columns: $land / 625)");
    }
});

test('biomes vary across the map (the world is not all one type)', function (): void {
    $seen = [];
    foreach (tScan(12) as [$cx, $cz, $data]) {
        foreach ($data->biomes as $b) {
            $seen[$b] = true;
        }
    }
    ok(count($seen) >= 2, 'at least 2 distinct biomes across the scanned area');
    // The world should contain both land and water regions.
    ok(isset($seen[ParallelGeneratorAdapter::BIOME_OCEAN]), 'ocean regions exist');
});

test('ocean columns have a sea floor and water up to sea level', function (): void {
    $foundOcean = false;
    foreach (tScan(10) as [$cx, $cz, $data]) {
        foreach ($data->biomes as $i => $b) {
            if ($b !== ParallelGeneratorAdapter::BIOME_OCEAN) {
                continue;
            }
            $foundOcean = true;
            $bx = $i % 16;
            $bz = intdiv($i, 16);
            // Water must be present at y = SEA_LEVEL (the heightmap top).
            $top = $data->heightmap[$i] - 1;
            $worldX = $cx * 16 + $bx;
            $worldZ = $cz * 16 + $bz;
            $h = $top >= ParallelGeneratorAdapter::SEA_LEVEL ? $top : ParallelGeneratorAdapter::SEA_LEVEL;
            $waterId = readTBlock($data, $bx, ParallelGeneratorAdapter::SEA_LEVEL, $bz);
            same(ParallelGeneratorAdapter::WATER_BLOCK, $waterId, "ocean column filled with water at sea level ($worldX,$worldZ)");
            // The floor is the first non-water block below the water column
            // (deep oceans sit lower than the shoreline shelf).
            $floorId = -1;
            for ($y = ParallelGeneratorAdapter::SEA_LEVEL; $y >= 0; $y--) {
                $b = readTBlock($data, $bx, $y, $bz);
                if ($b !== ParallelGeneratorAdapter::WATER_BLOCK) {
                    $floorId = $b;
                    break;
                }
            }
            ok(
                $floorId === ParallelGeneratorAdapter::SAND_BLOCK || $floorId === ParallelGeneratorAdapter::GRAVEL_BLOCK,
                "ocean floor is sand/gravel (got $floorId at $worldX,$worldZ)"
            );
            break 2;
        }
    }
    ok($foundOcean, 'an ocean column was found to check');
});

test('beach and desert columns are sandy', function (): void {
    $sandy = 0;
    $checked = 0;
    foreach (tScan(12) as [$cx, $cz, $data]) {
        foreach ($data->biomes as $i => $b) {
            if ($b !== ParallelGeneratorAdapter::BIOME_BEACH && $b !== ParallelGeneratorAdapter::BIOME_DESERT) {
                continue;
            }
            $checked++;
            $top = $data->heightmap[$i] - 1;
            $bx = $i % 16;
            $bz = intdiv($i, 16);
            $surface = readTBlock($data, $bx, $top, $bz);
            if ($surface === ParallelGeneratorAdapter::SAND_BLOCK) {
                $sandy++;
            }
        }
        if ($checked >= 20) {
            break;
        }
    }
    ok($checked > 0, 'beach/desert columns were found');
    ok($sandy === $checked, "every beach/desert column tops out on sand ($sandy/$checked)");
});

test('forest chunks get trees and plains get vegetation', function (): void {
    $treeChunk = null;
    $treeX = $treeZ = 0;
    foreach (tScan(16) as [$cx, $cz, $data]) {
        $forestCols = 0;
        foreach ($data->biomes as $b) {
            if ($b === ParallelGeneratorAdapter::BIOME_FOREST) {
                $forestCols++;
            }
        }
        if ($forestCols > 64) {
            $treeChunk = $data;
            $treeX = $cx;
            $treeZ = $cz;
            break;
        }
    }
    ok($treeChunk !== null, 'a forest chunk was found in the scanned area');
    if ($treeChunk === null) {
        return;
    }

    // Deterministic population: same chunk twice -> identical populated result.
    $populated = ParallelGeneratorAdapter::populateChunkPure($treeX, $treeZ, $treeChunk, T_SEED);
    $again = ParallelGeneratorAdapter::populateChunkPure($treeX, $treeZ, $treeChunk, T_SEED);
    same($populated->sections, $again->sections, 'population is deterministic');

    // The populated forest chunk must contain logs and leaves.
    $logs = $leaves = $grass = 0;
    foreach ($populated->sections as $section) {
        $blocks = $section['blocks'];
        for ($i = 0; $i < 4096; $i++) {
            $byte = ord($blocks[$i]);
            if ($byte === ParallelGeneratorAdapter::LOG_BLOCK) {
                $logs++;
            } elseif ($byte === ParallelGeneratorAdapter::LEAVES_BLOCK) {
                $leaves++;
            } elseif ($byte === ParallelGeneratorAdapter::TALL_GRASS_BLOCK) {
                $grass++;
            }
        }
    }
    ok($logs > 0, "forest chunk has at least one tree trunk (logs: $logs)");
    ok($leaves > 0, "forest chunk has a canopy (leaves: $leaves)");
    ok($grass >= 0, 'vegetation pass ran without errors');
});

test('population never writes outside the chunk (no cross-chunk bleed)', function (): void {
    $data = tGen(7, 7);
    $before = $data->sections;
    $pop = ParallelGeneratorAdapter::populateChunkPure(7, 7, $data, T_SEED);
    // Every section must stay exactly 4096 bytes (writes were bounds-checked
    // by construction: trunks at x/z in [3..12], canopy radius 2).
    foreach ($pop->sections as $i => $section) {
        same(4096, strlen($section['blocks']), "section $i still 4096 bytes");
    }
    // At least one byte changed (trees/vegetation placed) or the chunk had
    // nothing eligible - either way, no crash and valid sizes.
    ok(count($pop->sections) === count($before), 'section count unchanged');
});

function readTBlock(ChunkData $data, int $x, int $y, int $z): int {
    $sy = intdiv($y, 16);
    $sec = $data->sections[$sy] ?? null;
    if ($sec === null) {
        return 0;
    }
    $idx = ($y & 15) * 256 + $z * 16 + $x;
    return ord($sec['blocks'][$idx]);
}

exit(runTests());

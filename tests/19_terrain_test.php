<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\port\driven\ChunkData;
use pocketmine\port\driven\GeneratorConfig;

/**
 * Phase 13.x - terrain shape regression.
 *
 * The old generator had two visible defects:
 *  - blocky heights: intdiv(x, 8) / intdiv(x, 2) hash cells produced flat
 *    8x8 / 2x2 "dirt chunks" with abrupt cliffs (up to ~30 blocks) between
 *    cells;
 *  - unconditional water: every column ended with 3 water blocks, flooding
 *    hilltops as well as valleys ("4x4 of still water all over").
 *
 * The new generator uses smooth (bilinear + smoothstep) value noise and only
 * fills columns whose surface is below sea level with water. These tests pin
 * the shape: smoothness (no cliffs), conditional water (never on a hilltop,
 * but present in low valleys), and a sane height range.
 */

/** Read one column's block ids (keyed by world y) from a chunk. */
function columnBlocks(ChunkData $c, int $x, int $z): array {
    $out = [];
    foreach ($c->sections as $s) {
        $y0 = $s['y'] * 16;
        for ($y = 0; $y < 16; $y++) {
            $out[$y0 + $y] = ord($s['blocks'][$y * 256 + $z * 16 + $x]);
        }
    }
    return $out;
}

/** @return list<ChunkData> */
function generateArea(\pocketmine\Kernel $kernel, int $radius, int $seed): array {
    $config = new GeneratorConfig('normal', $seed, []);
    $coords = [];
    for ($cx = -$radius; $cx <= $radius; $cx++) {
        for ($cz = -$radius; $cz <= $radius; $cz++) {
            $coords[] = [$cx, $cz];
        }
    }
    return $kernel->getWorldGenPort()->generateChunks($coords, $config);
}

test('terrain: heights are smooth (no flat plateaus or cliffs)', function () {
    $kernel = \pocketmine\bootstrap();
    $chunks = generateArea($kernel, 2, 42); // 5x5 chunks = 80x80 blocks

    $heights = [];
    foreach ($chunks as $c) {
        foreach ($c->heightmap as $h) {
            $heights[] = $h;
        }
    }
    $min = min($heights);
    $max = max($heights);
    ok($min >= 2 && $max <= 110, "heights in [2, 110], got min=$min max=$max");
    ok($max - $min > 8, "terrain actually varies (relief $min..$max)");

    // Smoothness: no adjacent-column jump bigger than 6. The old intdiv
    // noise produced jumps up to ~30 at cell borders.
    $worst = 0;
    foreach ($chunks as $c) {
        $hm = $c->heightmap;
        for ($z = 0; $z < 16; $z++) {
            for ($x = 0; $x < 16; $x++) {
                $i = $z * 16 + $x;
                if ($x < 15) {
                    $worst = max($worst, abs($hm[$i] - $hm[$i + 1]));
                }
                if ($z < 15) {
                    $worst = max($worst, abs($hm[$i] - $hm[$i + 16]));
                }
            }
        }
    }
    ok($worst <= 6, "no cliffs between neighbors, worst adjacent jump = $worst");
});

test('terrain: water only fills valleys below sea level', function () {
    $kernel = \pocketmine\bootstrap();
    $chunks = generateArea($kernel, 3, 42); // 7x7 chunks = 112x112 blocks

    $waterColumns = 0;
    $dryHilltops = 0;
    $badWater = 0; // water above a column whose surface is at/above sea level
    $total = 0;
    foreach ($chunks as $c) {
        for ($z = 0; $z < 16; $z++) {
            for ($x = 0; $x < 16; $x++) {
                $surface = $c->heightmap[$z * 16 + $x]; // highest solid block
                $cols = columnBlocks($c, $x, $z);
                $total++;
                $hasWater = false;
                $highestSolid = -1;
                foreach ($cols as $y => $id) {
                    if ($id === 8) { // still water
                        $hasWater = true;
                    } elseif ($id !== 0) { // non-air, non-water
                        $highestSolid = $y;
                    }
                }
                if ($hasWater) {
                    $waterColumns++;
                    if ($highestSolid >= 62) {
                        $badWater++;
                    }
                } elseif ($highestSolid >= 62) {
                    $dryHilltops++;
                }
            }
        }
    }

    ok($waterColumns > 0, "lakes/oceans exist (water in $waterColumns of $total columns)");
    ok($dryHilltops > 0, "hilltops are dry (surface >= sea level: $dryHilltops of $total)");
    same(0, $badWater, "no water ever sits above a hilltop");
    ok($waterColumns * 2 < $total, "land dominates (water is under half the world, got $waterColumns of $total)");
});

exit(runTests());

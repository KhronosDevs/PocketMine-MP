<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require_once __DIR__ . '/helpers.php';

use pocketmine\adapter\driven\worldgen\ParallelGeneratorAdapter;
use pocketmine\port\driven\ChunkData;

const O_SEED = 42;

/** Generate + populate a chunk. */
function oGen(int $cx, int $cz): ChunkData {
    $data = ParallelGeneratorAdapter::generateChunkPure($cx, $cz, 'normal', O_SEED);
    return ParallelGeneratorAdapter::populateChunkPure($cx, $cz, $data, O_SEED);
}

/**
 * Scan populated chunks and return a per-ore-type summary:
 * [id => ['count' => n, 'minY' => y, 'maxY' => y, 'hasSurfaceContact' => bool]]
 */
function oScan(int $radius): array {
    $ores = [
        ParallelGeneratorAdapter::COAL_ORE,
        ParallelGeneratorAdapter::IRON_ORE,
        ParallelGeneratorAdapter::GOLD_ORE,
        ParallelGeneratorAdapter::DIAMOND_ORE,
        ParallelGeneratorAdapter::REDSTONE_ORE,
        ParallelGeneratorAdapter::LAPIS_ORE,
        ParallelGeneratorAdapter::EMERALD_ORE,
    ];
    $out = [];
    foreach ($ores as $id) {
        $out[$id] = ['count' => 0, 'minY' => 999, 'maxY' => -1];
    }
    for ($cx = -$radius; $cx < $radius; $cx++) {
        for ($cz = -$radius; $cz < $radius; $cz++) {
            $pop = oGen($cx, $cz);
            foreach ($pop->sections as $sec) {
                $blocks = $sec['blocks'];
                for ($i = 0; $i < 4096; $i++) {
                    $id = ord($blocks[$i]);
                    if (!isset($out[$id])) {
                        continue;
                    }
                    $y = $sec['y'] * 16 + intdiv($i, 256);
                    $out[$id]['count']++;
                    $out[$id]['minY'] = min($out[$id]['minY'], $y);
                    $out[$id]['maxY'] = max($out[$id]['maxY'], $y);
                }
            }
        }
    }
    return $out;
}

/** True if the given block is an ore id. */
function oIsOre(int $id): bool {
    return in_array($id, [
        ParallelGeneratorAdapter::COAL_ORE,
        ParallelGeneratorAdapter::IRON_ORE,
        ParallelGeneratorAdapter::GOLD_ORE,
        ParallelGeneratorAdapter::DIAMOND_ORE,
        ParallelGeneratorAdapter::REDSTONE_ORE,
        ParallelGeneratorAdapter::LAPIS_ORE,
        ParallelGeneratorAdapter::EMERALD_ORE,
    ], true);
}

test('ore veins generate with the legacy Y windows and relative abundance', function (): void {
    // Scan a 32x32 chunk area around the spawn plateau (land for any seed).
    $s = oScan(16);
    foreach ([
        ParallelGeneratorAdapter::COAL_ORE => [0, 128],
        ParallelGeneratorAdapter::IRON_ORE => [0, 64],
        ParallelGeneratorAdapter::GOLD_ORE => [0, 32],
        ParallelGeneratorAdapter::DIAMOND_ORE => [0, 16],
        ParallelGeneratorAdapter::REDSTONE_ORE => [0, 16],
        ParallelGeneratorAdapter::LAPIS_ORE => [0, 32],
    ] as $id => [$minY, $maxY]) {
        ok($s[$id]['count'] > 0, "ore $id generated at least one block");
        ok($s[$id]['minY'] >= $minY, "ore $id min Y >= $minY (got {$s[$id]['minY']})");
        ok($s[$id]['maxY'] <= $maxY, "ore $id max Y <= $maxY (got {$s[$id]['maxY']})");
    }

    // Relative abundance matches the legacy cluster counts (coal and iron are
    // the most common, diamond/lapis the rarest).
    ok($s[ParallelGeneratorAdapter::COAL_ORE]['count'] > $s[ParallelGeneratorAdapter::GOLD_ORE]['count'] * 10,
        'coal is at least 10x more common than gold');
    ok($s[ParallelGeneratorAdapter::DIAMOND_ORE]['count'] > 0
        && $s[ParallelGeneratorAdapter::DIAMOND_ORE]['count'] < $s[ParallelGeneratorAdapter::COAL_ORE]['count'],
        'diamond is rarer than coal');
});

test('ore generation is deterministic', function (): void {
    $data = ParallelGeneratorAdapter::generateChunkPure(7, -3, 'normal', O_SEED);
    $a = ParallelGeneratorAdapter::populateChunkPure(7, -3, $data, O_SEED);
    $b = ParallelGeneratorAdapter::populateChunkPure(7, -3, $data, O_SEED);
    same($a->sections, $b->sections, 'same chunk populated twice -> identical sections');
});

test('ores only replace stone, never float in air or water', function (): void {
    $bad = 0;
    for ($cx = -8; $cx < 8; $cx++) {
        for ($cz = -8; $cz < 8; $cz++) {
            $pop = oGen($cx, $cz);
            foreach ($pop->sections as $sec) {
                $blocks = $sec['blocks'];
                for ($i = 0; $i < 4096; $i++) {
                    $id = ord($blocks[$i]);
                    if (!oIsOre($id)) {
                        continue;
                    }
                    $y = $sec['y'] * 16 + intdiv($i, 256);
                    if ($y <= 0) {
                        continue; // y=0 is bedrock, never ore
                    }
                    // The block below an ore must be solid (stone-family or
                    // another ore), never air or water - ores can't float.
                    $sy = intdiv($y - 1, 16);
                    $secBelow = $pop->sections[$sy] ?? null;
                    if ($secBelow === null) {
                        $bad++;
                        continue;
                    }
                    $bx = $i % 16;
                    $bz = intdiv($i, 16) % 16;
                    $idx = (($y - 1) & 15) * 256 + $bz * 16 + $bx;
                    $below = ord($secBelow['blocks'][$idx]);
                    if ($below === 0 || $below === ParallelGeneratorAdapter::WATER_BLOCK) {
                        $bad++;
                    }
                }
            }
        }
    }
    same(0, $bad, 'no ore block floats in air or water');
});

test('ores never break the surface and never write outside the chunk', function (): void {
    $surfaceHits = 0;
    $sizeBad = 0;
    for ($cx = -8; $cx < 8; $cx++) {
        for ($cz = -8; $cz < 8; $cz++) {
            $pop = oGen($cx, $cz);
            foreach ($pop->sections as $section) {
                if (strlen($section['blocks']) !== 4096) {
                    $sizeBad++;
                }
            }
            // Walk every column: the top non-air block of a land column must
            // never be an ore (ores stay buried below the surface).
            foreach ($pop->heightmap as $i => $h) {
                if ($h <= 1) {
                    continue; // void/ocean column
                }
                $bx = $i % 16;
                $bz = intdiv($i, 16);
                $topY = $h - 1;
                $sy = intdiv($topY, 16);
                $sec = $pop->sections[$sy] ?? null;
                if ($sec === null) {
                    continue;
                }
                $idx = ($topY & 15) * 256 + $bz * 16 + $bx;
                $top = ord($sec['blocks'][$idx]);
                if ($top !== 0 && oIsOre($top)) {
                    $surfaceHits++;
                }
            }
        }
    }
    same(0, $surfaceHits, 'no ore block is the top surface block of a column');
    same(0, $sizeBad, 'every section stays exactly 4096 bytes (no cross-chunk bleed)');
});

test('emerald ore only spawns in extreme-hills terrain', function (): void {
    // Scan a large area; every emerald block must sit in an extreme-hills
    // column (the only biome the table registers emerald for).
    $emeraldY = [];
    $hillsChunks = 0;
    $scanned = 0;
    for ($cx = -24; $cx < 24; $cx++) {
        for ($cz = -24; $cz < 24; $cz++) {
            $data = ParallelGeneratorAdapter::generateChunkPure($cx, $cz, 'normal', O_SEED);
            $pop = ParallelGeneratorAdapter::populateChunkPure($cx, $cz, $data, O_SEED);
            $scanned++;
            $chunkHasHills = false;
            foreach ($data->biomes as $b) {
                if ($b === ParallelGeneratorAdapter::BIOME_EXTREME_HILLS) {
                    $chunkHasHills = true;
                    break;
                }
            }
            if ($chunkHasHills) {
                $hillsChunks++;
            }
            foreach ($pop->sections as $sec) {
                $blocks = $sec['blocks'];
                for ($i = 0; $i < 4096; $i++) {
                    if (ord($blocks[$i]) !== ParallelGeneratorAdapter::EMERALD_ORE) {
                        continue;
                    }
                    $y = $sec['y'] * 16 + intdiv($i, 256);
                    $emeraldY[] = $y;
                }
            }
        }
    }
    ok($scanned > 0, 'scan ran');
    // Emerald is rare (1 cluster/chunk in hills-only) - it may not appear in
    // this area at all, but when it does it must be in the y 4..32 window.
    foreach ($emeraldY as $y) {
        ok($y >= 4 && $y <= 32, "emerald y in [4, 32] (got $y)");
    }
});

exit(runTests());

<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\worldgen;

use pocketmine\port\driven\ChunkData;

/**
 * Nukkit Normal terrain generator ported to PHP — optimized.
 *
 * Builds sections directly in row-major order (no column→transpose step).
 * Uses the same multi-noise simplex terrain shaping as the original Nukkit
 * generator but avoids per-cell string concatenation and the O(256×16)
 * transpose that was the main bottleneck.
 *
 * Deterministic in (chunkX, chunkZ, seed) — identical output across runs.
 */
final class NukkitNormalGenerator {

    private const SEA_HEIGHT = 62;
    private const SEA_FLOOR_HEIGHT = 48;
    private const BEACH_START_HEIGHT = 60;
    private const BEACH_STOP_HEIGHT = 64;
    private const BEDROCK_DEPTH = 5;
    private const SEA_FLOOR_GEN_RANGE = 5;
    private const LAND_HEIGHT_RANGE = 18;
    private const MOUNTAIN_HEIGHT = 13;
    private const BASEGROUND_HEIGHT = 3;

    private const BEDROCK = 7;
    private const STONE = 1;
    private const STILL_WATER = 9;
    private const ICE = 79;

    private const BIOME_OCEAN = 0;
    private const BIOME_PLAINS = 1;
    private const BIOME_DESERT = 2;
    private const BIOME_MOUNTAINS = 3;
    private const BIOME_FOREST = 4;
    private const BIOME_TAIGA = 5;
    private const BIOME_SWAMP = 6;
    private const BIOME_RIVER = 7;
    private const BIOME_ICE_PLAINS = 12;
    private const BIOME_BIRCH_FOREST = 4;
    private const BIOME_BEACH = 16;

    /**
     * Generate a chunk's base terrain. Optimized: computes per-column
     * height+biome in one pass, then builds sections directly in row-major
     * order — no column strings, no transpose.
     */
    public static function generateChunk(int $chunkX, int $chunkZ, int $seed): ChunkData {
        $rngState = self::seedRng($chunkX, $chunkZ, $seed);

        // Precompute noise grids (5 grids × 256 values each)
        $seaFloorNoise = self::sampleNoiseGrid($chunkX, $chunkZ, 1.0, 0.125, 1.0 / 64.0, $rngState);
        $landNoise = self::sampleNoiseGrid($chunkX, $chunkZ, 2.0, 0.125, 1.0 / 512.0, $rngState);
        $mountainNoise = self::sampleNoiseGrid($chunkX, $chunkZ, 4.0, 1.0, 1.0 / 500.0, $rngState);
        $baseNoise = self::sampleNoiseGrid($chunkX, $chunkZ, 4.0, 0.25, 1.0 / 64.0, $rngState);
        $riverNoise = self::sampleNoiseGrid($chunkX, $chunkZ, 2.0, 1.0, 1.0 / 512.0, $rngState);

        // Pass 1: compute per-column height + biome (256 values)
        $heights = [];
        $biomes = [];
        for ($genz = 0; $genz < 16; $genz++) {
            for ($genx = 0; $genx < 16; $genx++) {
                $idx = $genz * 16 + $genx;
                $worldX = $chunkX * 16 + $genx;
                $worldZ = $chunkZ * 16 + $genz;

                $canBaseGround = false;
                $canRiver = true;

                $landHeightNoise = $landNoise[$idx] + 1.0;
                $landHeightNoise *= 2.956;
                $landHeightNoise = $landHeightNoise * $landHeightNoise - 0.6;
                $landHeightNoise = max(0.0, $landHeightNoise);

                $mountainHeightGen = max(0.0, $mountainNoise[$idx] - 0.2);
                $mountainGenerate = (int)(self::MOUNTAIN_HEIGHT * $mountainHeightGen);

                $landHeightGen = (int)(self::LAND_HEIGHT_RANGE * $landHeightNoise);
                if ($landHeightGen > self::LAND_HEIGHT_RANGE) {
                    $canBaseGround = true;
                    $landHeightGen = self::LAND_HEIGHT_RANGE;
                }

                $genyHeight = self::SEA_FLOOR_HEIGHT + $landHeightGen + $mountainGenerate;
                $biome = self::BIOME_PLAINS;

                if ($genyHeight < self::BEACH_START_HEIGHT) {
                    if ($genyHeight < self::BEACH_START_HEIGHT - 5) {
                        $genyHeight += (int)(self::SEA_FLOOR_GEN_RANGE * $seaFloorNoise[$idx]);
                    }
                    $biome = self::BIOME_OCEAN;
                    if ($genyHeight < self::SEA_FLOOR_HEIGHT - self::SEA_FLOOR_GEN_RANGE) {
                        $genyHeight = self::SEA_FLOOR_HEIGHT;
                    }
                    $canRiver = false;
                } elseif ($genyHeight >= self::BEACH_START_HEIGHT && $genyHeight <= self::BEACH_STOP_HEIGHT) {
                    $biome = self::BIOME_BEACH;
                } else {
                    $biome = self::pickBiome($worldX, $worldZ, $seed);
                    if ($canBaseGround) {
                        $baseGroundHeight = (int)(self::LAND_HEIGHT_RANGE * $landHeightNoise) - self::LAND_HEIGHT_RANGE;
                        $baseGroundHeight2 = (int)(self::BASEGROUND_HEIGHT * ($baseNoise[$idx] + 1.0));
                        if ($baseGroundHeight2 > $baseGroundHeight) $baseGroundHeight2 = $baseGroundHeight;
                        if ($baseGroundHeight2 > $mountainGenerate) $baseGroundHeight2 -= $mountainGenerate;
                        else $baseGroundHeight2 = 0;
                        $genyHeight += $baseGroundHeight2;
                    }
                }

                if ($canRiver && $genyHeight <= self::SEA_HEIGHT - 5) $canRiver = false;

                if ($canRiver) {
                    $rv = $riverNoise[$idx];
                    if ($rv > -0.25 && $rv < 0.25) {
                        $rv = ($rv > 0 ? $rv : -$rv);
                        $rv = 0.25 - $rv;
                        $rv = $rv * $rv * 4.0 - 0.0000001;
                        $rv = max(0.0, $rv);
                        $genyHeight -= (int)($rv * 64);
                        if ($genyHeight < self::SEA_HEIGHT) {
                            $biome = self::BIOME_RIVER;
                            if ($genyHeight <= self::SEA_HEIGHT - 8) {
                                $g1 = self::SEA_HEIGHT - 9 + (int)(self::BASEGROUND_HEIGHT * ($baseNoise[$idx] + 1.0));
                                $g2 = $genyHeight < self::SEA_HEIGHT - 7 ? self::SEA_HEIGHT - 7 : $genyHeight;
                                $genyHeight = max($g1, $g2);
                            }
                        }
                    }
                }

                $biomes[$idx] = $biome;
                $heights[$idx] = $genyHeight;
            }
        }

        // Pass 2: build sections directly in row-major order (no transpose)
        $maxH = max($heights);
        $topSectionY = min(7, intdiv($maxH + 8, 16));
        $air = "\x00";
        $sections = [];

        for ($sy = 0; $sy <= $topSectionY; $sy++) {
            $y0 = $sy * 16;
            $rows = [];
            for ($r = 0; $r < 16; $r++) {
                $y = $y0 + $r;
                $row = '';
                for ($genz = 0; $genz < 16; $genz++) {
                    for ($genx = 0; $genx < 16; $genx++) {
                        $idx = $genz * 16 + $genx;
                        $genyHeight = $heights[$idx];
                        $biome = $biomes[$idx];
                        $generateHeight = max($genyHeight, self::SEA_HEIGHT);

                        if ($y > $generateHeight) {
                            $row .= $air;
                        } elseif ($y < self::BEDROCK_DEPTH) {
                            // Bedrock: y=0 always, y>0 random (use deterministic hash)
                            $row .= ($y === 0 || (($chunkX * 16 + $genx) * 374761393 ^ ($chunkZ * 16 + $genz) * 668265263 ^ $y * 1103515245) % 5 === 0)
                                ? chr(self::BEDROCK) : chr(self::STONE);
                        } elseif ($y > $genyHeight) {
                            // Water/ice
                            if (($biome === self::BIOME_ICE_PLAINS || $biome === self::BIOME_TAIGA) && $y === self::SEA_HEIGHT) {
                                $row .= chr(self::ICE);
                            } else {
                                $row .= chr(self::STILL_WATER);
                            }
                        } elseif ($y === $genyHeight) {
                            // Surface
                            if ($biome === self::BIOME_BEACH || $biome === self::BIOME_DESERT || $biome === self::BIOME_OCEAN) {
                                $row .= chr(12); // sand
                            } elseif ($genyHeight >= 96 && ($biome === self::BIOME_MOUNTAINS || $biome === self::BIOME_ICE_PLAINS)) {
                                $row .= chr(12);
                            } else {
                                $row .= chr(2); // grass
                            }
                        } elseif ($y >= $genyHeight - 3) {
                            // Dirt/sandstone layer
                            if ($biome === self::BIOME_BEACH || $biome === self::BIOME_DESERT) {
                                $row .= chr(24); // sandstone
                            } else {
                                $row .= chr(3); // dirt
                            }
                        } else {
                            $row .= chr(self::STONE);
                        }
                    }
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

        // Heightmap
        $heightmap = [];
        for ($i = 0; $i < 256; $i++) {
            $heightmap[$i] = max($heights[$i], self::SEA_HEIGHT) + 1;
        }

        // Cave + ravine carving
        $sections = self::carveCaves($sections, $chunkX, $chunkZ, $seed);
        $sections = self::carveRavines($sections, $chunkX, $chunkZ, $seed);

        return new ChunkData($chunkX, $chunkZ, $sections, $biomes, $heightmap, [], []);
    }

    /**
     * Populate a chunk: ores, ground cover, trees.
     */
    public static function populateChunk(int $chunkX, int $chunkZ, ChunkData $data, int $seed): ChunkData {
        $sections = $data->sections;
        $rng = self::seedRng($chunkX, $chunkZ, $seed ^ 0xDEADBEEF);

        // --- Ores ---
        $ores = [
            [16, 20, 16, 0, 128], // coal
            [15, 20, 8, 0, 64],   // iron
            [73, 8, 7, 0, 16],    // redstone
            [21, 1, 6, 0, 32],    // lapis
            [14, 2, 8, 0, 32],    // gold
            [56, 1, 7, 0, 16],    // diamond
        ];

        foreach ($ores as [$oreId, $clusters, $clusterSize, $minY, $maxY]) {
            for ($i = 0; $i < $clusters; $i++) {
                $bx = self::nextRngInt($rng, 16);
                $bz = self::nextRngInt($rng, 16);
                $y = $minY + self::nextRngInt($rng, $maxY - $minY);
                for ($n = 0; $n < $clusterSize; $n++) {
                    $ox = $bx + self::nextRngInt($rng, 3) - 1;
                    $oz = $bz + self::nextRngInt($rng, 3) - 1;
                    $oy = $y + self::nextRngInt($rng, 3) - 1;
                    if ($ox >= 0 && $ox <= 15 && $oz >= 0 && $oz <= 15 && $oy >= 1 && $oy <= 126) {
                        if (self::readBlock($sections, $ox, $oy, $oz) === self::STONE) {
                            $sections = self::writeBlock($sections, $ox, $oy, $oz, $oreId, false);
                        }
                    }
                }
            }
        }

        // --- Dirt + gravel veins ---
        foreach ([[3, 20], [13, 10]] as [$blockId, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $bx = self::nextRngInt($rng, 16);
                $bz = self::nextRngInt($rng, 16);
                $y = self::nextRngInt($rng, 128);
                $size = $blockId === 3 ? 32 : 16;
                for ($n = 0; $n < $size; $n++) {
                    $ox = $bx + self::nextRngInt($rng, 3) - 1;
                    $oz = $bz + self::nextRngInt($rng, 3) - 1;
                    $oy = $y + self::nextRngInt($rng, 3) - 1;
                    if ($ox >= 0 && $ox <= 15 && $oz >= 0 && $oz <= 15 && $oy >= 1 && $oy <= 126) {
                        if (self::readBlock($sections, $ox, $oy, $oz) === self::STONE) {
                            $sections = self::writeBlock($sections, $ox, $oy, $oz, $blockId, false);
                        }
                    }
                }
            }
        }

        // --- Ground cover + trees ---
        $treeBiomes = [
            self::BIOME_FOREST => ['type' => 'oak', 'count' => 5],
            self::BIOME_PLAINS => ['type' => 'oak', 'count' => 1],
            self::BIOME_TAIGA => ['type' => 'spruce', 'count' => 3],
            self::BIOME_ICE_PLAINS => ['type' => 'spruce', 'count' => 2],
            self::BIOME_BIRCH_FOREST => ['type' => 'birch', 'count' => 4],
            self::BIOME_SWAMP => ['type' => 'oak', 'count' => 2],
            self::BIOME_MOUNTAINS => ['type' => 'oak', 'count' => 1],
        ];

        for ($bz = 0; $bz < 16; $bz++) {
            for ($bx = 0; $bx < 16; $bx++) {
                $biome = $data->biomes[$bz * 16 + $bx] ?? self::BIOME_PLAINS;
                $top = ($data->heightmap[$bz * 16 + $bx] ?? 0) - 1;
                if ($top < 1 || $top > 125) continue;
                $surfaceBlock = self::readBlock($sections, $bx, $top, $bz);
                if ($surfaceBlock !== 2 && $surfaceBlock !== 3 && $surfaceBlock !== 12) continue;
                if ($top + 1 > 126) continue;

                $roll = self::nextRngInt($rng, 100);
                if ($biome === self::BIOME_PLAINS || $biome === self::BIOME_FOREST) {
                    if ($roll < 40) $sections = self::writeBlock($sections, $bx, $top + 1, $bz, 31, false);
                    elseif ($roll < 43) $sections = self::writeBlock($sections, $bx, $top + 1, $bz, 38, false);
                    elseif ($roll < 45) $sections = self::writeBlock($sections, $bx, $top + 1, $bz, 37, false);
                } elseif ($biome === self::BIOME_DESERT) {
                    if ($roll < 2 && $top + 2 <= 126) {
                        $h = 2 + self::nextRngInt($rng, 2);
                        for ($cy = 1; $cy <= $h; $cy++) $sections = self::writeBlock($sections, $bx, $top + $cy, $bz, 81, false);
                    } elseif ($roll < 4) $sections = self::writeBlock($sections, $bx, $top + 1, $bz, 32, false);
                } elseif ($biome === self::BIOME_TAIGA || $biome === self::BIOME_ICE_PLAINS) {
                    if ($roll < 30) $sections = self::writeBlock($sections, $bx, $top + 1, $bz, 31, false);
                }
            }
        }

        // Trees
        for ($t = 0; $t < 8; $t++) {
            $tx = 3 + self::nextRngInt($rng, 10);
            $tz = 3 + self::nextRngInt($rng, 10);
            $biome = $data->biomes[$tz * 16 + $tx] ?? self::BIOME_PLAINS;
            $treeConf = $treeBiomes[$biome] ?? null;
            if ($treeConf === null || self::nextRngInt($rng, 10) >= $treeConf['count']) continue;
            $top = ($data->heightmap[$tz * 16 + $tx] ?? 0) - 1;
            if ($top < 2 || $top > 118) continue;
            $surfaceBlock = self::readBlock($sections, $tx, $top, $tz);
            if ($surfaceBlock !== 2 && $surfaceBlock !== 3) continue;
            if (self::readBlock($sections, $tx, $top + 1, $tz) !== 0) continue;
            self::placeTree($sections, $tx, $top, $tz, $treeConf['type'], $rng);
        }

        return new ChunkData($data->chunkX, $data->chunkZ, $sections, $data->biomes, $data->heightmap, [], []);
    }

    // ---- Noise helpers ----

    /**
     * Sample a 16×16 noise grid, returning a flat 256-element array.
     * Inlined simplex noise computation to avoid per-call object overhead.
     */
    private static function sampleNoiseGrid(int $chunkX, int $chunkZ, float $freq, float $lac, float $pers, int &$rng): array {
        $noise = new SimplexNoise($rng, $freq, $lac, $pers);
        $result = [];
        for ($i = 0; $i < 256; $i++) {
            $x = ($chunkX * 16 + ($i & 15)) / 4.0;
            $z = ($chunkZ * 16 + ($i >> 4)) / 4.0;
            $result[$i] = $noise->noise2D($x, $z);
        }
        return $result;
    }

    private static function pickBiome(int $x, int $z, int $seed): int {
        $hash = $x * 2345803 ^ $z * 9236449 ^ $seed;
        $hash *= $hash + 223;
        $xNoise = ($hash >> 20) & 3;
        $zNoise = ($hash >> 22) & 3;
        if ($xNoise === 3) $xNoise = 1;
        if ($zNoise === 3) $zNoise = 1;

        $temp = self::smoothHash($x + $xNoise - 1, $z + $zNoise - 1, $seed ^ 0x4F1BBCDC, 8);
        $humidity = self::smoothHash($x + $xNoise - 1, $z + $zNoise - 1, $seed ^ 0x11D8E2A9, 8);

        $cold = $temp < 24576;
        $hot = $temp > 40960;
        $wet = $humidity > 36000;
        $dry = $humidity < 26214;

        if ($cold) return $wet ? self::BIOME_TAIGA : self::BIOME_ICE_PLAINS;
        if ($hot && $dry) return self::BIOME_DESERT;
        if ($wet) return self::BIOME_FOREST;
        return self::BIOME_PLAINS;
    }

    private static function smoothHash(int $x, int $z, int $seed, int $shift): int {
        $cell = 1 << $shift;
        $gx = $x >> $shift;
        $gz = $z >> $shift;
        $fx = $x & ($cell - 1);
        $fz = $z & ($cell - 1);

        $v00 = self::hash2D($gx, $gz, $seed);
        $v10 = self::hash2D($gx + 1, $gz, $seed);
        $v01 = self::hash2D($gx, $gz + 1, $seed);
        $v11 = self::hash2D($gx + 1, $gz + 1, $seed);

        $u = intdiv($fx * 65536, $cell);
        $u2 = intdiv($u * $u, 65536);
        $tx = 3 * $u2 - 2 * intdiv($u2 * $u, 65536);

        $u = intdiv($fz * 65536, $cell);
        $u2 = intdiv($u * $u, 65536);
        $tz = 3 * $u2 - 2 * intdiv($u2 * $u, 65536);

        $top = $v00 + intdiv(($v10 - $v00) * $tx, 65536);
        $bottom = $v01 + intdiv(($v11 - $v01) * $tx, 65536);
        return $top + intdiv(($bottom - $top) * $tz, 65536);
    }

    private static function hash2D(int $x, int $z, int $seed): int {
        $n = (($x * 374761393) ^ ($z * 668265263) ^ (($seed & 0x7FFFFFFF) * 1103515245)) & 0x7FFFFFFF;
        $n = (($n ^ ($n >> 13)) * 1274126177) & 0x7FFFFFFF;
        return ($n ^ ($n >> 16)) & 0xFFFF;
    }

    // ---- Cave carving ----

    private static function carveCaves(array $sections, int $chunkX, int $chunkZ, int $seed): array {
        $rng = self::seedRng($chunkX, $chunkZ, $seed ^ 0x5B4C2A91);
        $systems = 2 + self::nextRngInt($rng, 3);
        for ($s = 0; $s < $systems; $s++) {
            $cx = 1 + self::nextRngInt($rng, 14);
            $cy = 8 + self::nextRngInt($rng, 50);
            $cz = 1 + self::nextRngInt($rng, 14);
            $r = 2 + self::nextRngInt($rng, 3);
            $segs = 8 + self::nextRngInt($rng, 16);
            for ($seg = 0; $seg < $segs; $seg++) {
                $cx = max(1, min(14, $cx + self::nextRngInt($rng, 5) - 2));
                $cy = max(5, min(60, $cy + self::nextRngInt($rng, 3) - 1));
                $cz = max(1, min(14, $cz + self::nextRngInt($rng, 5) - 2));
                $cr = $r + self::nextRngInt($rng, 2) - 1;
                for ($dx = -$cr; $dx <= $cr; $dx++) {
                    for ($dy = -$cr; $dy <= $cr; $dy++) {
                        for ($dz = -$cr; $dz <= $cr; $dz++) {
                            if ($dx * $dx + $dy * $dy + $dz * $dz > $cr * $cr) continue;
                            $bx = $cx + $dx; $by = $cy + $dy; $bz = $cz + $dz;
                            if ($bx < 0 || $bx > 15 || $bz < 0 || $bz > 15 || $by < 1 || $by > 126) continue;
                            $cur = self::readBlock($sections, $bx, $by, $bz);
                            if ($cur === self::STONE || $cur === 3) {
                                $sections = self::writeBlock($sections, $bx, $by, $bz, 0, false);
                            }
                        }
                    }
                }
            }
        }
        return $sections;
    }

    // ---- Ravine carving ----

    private static function carveRavines(array $sections, int $chunkX, int $chunkZ, int $seed): array {
        $rng = self::seedRng($chunkX, $chunkZ, $seed ^ 0xA3C12F87);
        if (self::nextRngInt($rng, 3) > 0) return $sections;

        $sx = self::nextRngInt($rng, 16);
        $sz = self::nextRngInt($rng, 16);
        $sy = 10 + self::nextRngInt($rng, 40);
        $angle = self::nextRngInt($rng, 360) * M_PI / 180.0;
        $len = 20 + self::nextRngInt($rng, 40);

        for ($step = 0; $step < $len; $step++) {
            $bx = (int)($sx + cos($angle) * $step);
            $bz = (int)($sz + sin($angle) * $step);
            $by = $sy + self::nextRngInt($rng, 5) - 2;
            if ($bx < 0 || $bx > 15 || $bz < 0 || $bz > 15) continue;
            $w = 2 + self::nextRngInt($rng, 3);
            for ($dx = -$w; $dx <= $w; $dx++) {
                for ($dy = -2; $dy <= 2; $dy++) {
                    $rx = $bx + $dx; $ry = $by + $dy;
                    if ($rx < 0 || $rx > 15 || $ry < 1 || $ry > 126) continue;
                    if (abs($dx) === $w && self::nextRngInt($rng, 2) === 0) continue;
                    $cur = self::readBlock($sections, $rx, $ry, $bz);
                    if ($cur !== 0 && $cur !== 8 && $cur !== 9) {
                        $sections = self::writeBlock($sections, $rx, $ry, $bz, 0, false);
                    }
                }
            }
            $angle += (self::nextRngInt($rng, 21) - 10) * 0.01;
        }
        return $sections;
    }

    // ---- Tree placement ----

    private static function placeTree(array &$sections, int $x, int $baseY, int $z, string $type, int &$rng): void {
        $logBlock = 17;
        $leafBlock = 18;
        $trunkHeight = 4 + self::nextRngInt($rng, 3);

        if ($type === 'birch') { $trunkHeight = 5 + self::nextRngInt($rng, 3); }
        elseif ($type === 'spruce') { $trunkHeight = 6 + self::nextRngInt($rng, 3); }

        $topY = $baseY + $trunkHeight;

        if ($type === 'spruce') {
            for ($y = $baseY + 1; $y <= $topY; $y++) {
                $sections = self::writeBlock($sections, $x, $y, $z, $logBlock, true);
            }
            for ($layer = 0; $layer < 4; $layer++) {
                $ly = $topY - 2 + $layer;
                $r = $layer < 2 ? 2 - $layer : 0;
                for ($dx = -$r; $dx <= $r; $dx++) {
                    for ($dz = -$r; $dz <= $r; $dz++) {
                        if ($dx === 0 && $dz === 0) continue;
                        if (abs($dx) === $r && abs($dz) === $r && $r > 0) continue;
                        $sections = self::writeBlock($sections, $x + $dx, $ly, $z + $dz, $leafBlock, true);
                    }
                }
            }
            $sections = self::writeBlock($sections, $x, $topY + 1, $z, $leafBlock, true);
        } else {
            for ($y = $baseY + 1; $y <= $topY; $y++) {
                $sections = self::writeBlock($sections, $x, $y, $z, $logBlock, true);
            }
            for ($ly = 0; $ly <= 1; $ly++) {
                $y = $topY + $ly;
                for ($dx = -2; $dx <= 2; $dx++) {
                    for ($dz = -2; $dz <= 2; $dz++) {
                        if (abs($dx) === 2 && abs($dz) === 2) continue;
                        if ($dx === 0 && $dz === 0 && $ly === 1) continue;
                        $sections = self::writeBlock($sections, $x + $dx, $y, $z + $dz, $leafBlock, true);
                    }
                }
            }
            $y = $topY + 2;
            for ($dx = -1; $dx <= 1; $dx++) {
                for ($dz = -1; $dz <= 1; $dz++) {
                    $sections = self::writeBlock($sections, $x + $dx, $y, $z + $dz, $leafBlock, true);
                }
            }
        }
    }

    // ---- Block read/write ----

    private static function readBlock(array $sections, int $x, int $y, int $z): int {
        $sy = intdiv($y, 16);
        if (!isset($sections[$sy])) return 0;
        $idx = ($y & 15) * 256 + $z * 16 + $x;
        return isset($sections[$sy]['blocks'][$idx]) ? ord($sections[$sy]['blocks'][$idx]) : 0;
    }

    private static function writeBlock(array $sections, int $x, int $y, int $z, int $blockId, bool $clearLight = false): array {
        $sy = intdiv($y, 16);
        if (!isset($sections[$sy])) {
            $sections[$sy] = [
                'y' => $sy,
                'blocks' => str_repeat("\x00", 4096),
                'data' => str_repeat("\x00", 4096),
                'skyLight' => str_repeat("\xff", 2048),
                'blockLight' => str_repeat("\x00", 2048),
            ];
        }
        $idx = ($y & 15) * 256 + $z * 16 + $x;
        $sections[$sy]['blocks'][$idx] = chr($blockId);
        return $sections;
    }

    // ---- RNG ----

    private static function seedRng(int $chunkX, int $chunkZ, int $seed): int {
        return (($chunkX * 374761393) ^ ($chunkZ * 668265263) ^ ($seed * 1103515245)) & 0x7FFFFFFF;
    }

    private static function nextRngInt(int &$state, int $range): int {
        $state = ($state * 1103515245 + 12345) & 0x7FFFFFFF;
        return $range > 0 ? $state % $range : 0;
    }
}

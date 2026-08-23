<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\worldgen;

use pocketmine\port\driven\ChunkData;

/**
 * Nukkit Normal terrain generator ported to PHP.
 *
 * Uses multiple simplex noise layers for terrain shaping (sea floor, land,
 * mountains, base ground, rivers) with a BiomeSelector for biome assignment.
 * Includes caves, ravines, ore veins, and ground cover (grass, flowers, etc.).
 *
 * Deterministic in (chunkX, chunkZ, seed) — identical output across runs.
 */
final class NukkitNormalGenerator {

    // Sea / terrain height constants (from Nukkit)
    private const SEA_HEIGHT = 62;
    private const SEA_FLOOR_HEIGHT = 48;
    private const BEACH_START_HEIGHT = 60;
    private const BEACH_STOP_HEIGHT = 64;
    private const BEDROCK_DEPTH = 5;
    private const SEA_FLOOR_GEN_RANGE = 5;
    private const LAND_HEIGHT_RANGE = 18; // 36 / 2
    private const MOUNTAIN_HEIGHT = 13;   // 26 / 2
    private const BASEGROUND_HEIGHT = 3;

    // Block ids (protocol-84, matching ParallelGeneratorAdapter)
    private const BEDROCK = 7;
    private const STONE = 1;
    private const WATER = 8;
    private const STILL_WATER = 9;
    private const ICE = 79;

    // Biome ids
    private const BIOME_OCEAN = 0;
    private const BIOME_PLAINS = 1;
    private const BIOME_DESERT = 2;
    private const BIOME_MOUNTAINS = 3; // extreme hills
    private const BIOME_FOREST = 4;
    private const BIOME_TAIGA = 5;
    private const BIOME_SWAMP = 6;
    private const BIOME_RIVER = 7;
    private const BIOME_ICE_PLAINS = 12;
    private const BIOME_SMALL_MOUNTAINS = 3; // same as extreme hills
    private const BIOME_BIRCH_FOREST = 4;   // use forest id for now
    private const BIOME_BEACH = 16;

    /**
     * Generate a chunk's base terrain (stone + water + biomes).
     * This runs on worker threads — must be pure (no instance state).
     */
    public static function generateChunk(int $chunkX, int $chunkZ, int $seed): ChunkData {
        // Deterministic RNG seed for this chunk
        $localSeed1 = self::hashSeed($seed, 1);
        $localSeed2 = self::hashSeed($seed, 2);

        // Initialize simplex noise instances
        $rngState = self::seedRng($chunkX, $chunkZ, $seed);
        $noiseSeaFloor = new SimplexNoise($rngState, 1.0, 1.0 / 8.0, 1.0 / 64.0);
        $noiseLand = new SimplexNoise($rngState, 2.0, 1.0 / 8.0, 1.0 / 512.0);
        $noiseMountains = new SimplexNoise($rngState, 4.0, 1.0, 1.0 / 500.0);
        $noiseBaseGround = new SimplexNoise($rngState, 4.0, 1.0 / 4.0, 1.0 / 64.0);
        $noiseRiver = new SimplexNoise($rngState, 2.0, 1.0, 1.0 / 512.0);

        // Height offset (random per-world, but deterministic from seed)
        $heightOffset = (self::hashSeed($seed, 3) % 9) - 5; // range [-5, 3]

        // Generate noise grids (16×16 with downsampling factor 4)
        $seaFloorNoise = self::sampleNoise2D($noiseSeaFloor, $chunkX, $chunkZ, $seed);
        $landNoise = self::sampleNoise2D($noiseLand, $chunkX, $chunkZ, $seed);
        $mountainNoise = self::sampleNoise2D($noiseMountains, $chunkX, $chunkZ, $seed);
        $baseNoise = self::sampleNoise2D($noiseBaseGround, $chunkX, $chunkZ, $seed);
        $riverNoise = self::sampleNoise2D($noiseRiver, $chunkX, $chunkZ, $seed);

        // Per-column data
        $biomes = [];
        $heightmap = [];
        $columnBlocks = [];

        for ($genz = 0; $genz < 16; $genz++) {
            for ($genx = 0; $genx < 16; $genx++) {
                $worldX = $chunkX * 16 + $genx;
                $worldZ = $chunkZ * 16 + $genz;

                $canBaseGround = false;
                $canRiver = true;

                // Land height: quadratic smoothing
                // y = (2.956x)^2 - 0.6, (0 <= x <= 2)
                $landHeightNoise = $landNoise[$genz][$genx] + 1.0;
                $landHeightNoise *= 2.956;
                $landHeightNoise = $landHeightNoise * $landHeightNoise;
                $landHeightNoise -= 0.6;
                $landHeightNoise = max(0.0, $landHeightNoise);

                // Mountain height
                $mountainHeightGen = $mountainNoise[$genz][$genx] - 0.2;
                $mountainHeightGen = max(0.0, $mountainHeightGen);
                $mountainGenerate = (int)(self::MOUNTAIN_HEIGHT * $mountainHeightGen);

                $landHeightGen = (int)(self::LAND_HEIGHT_RANGE * $landHeightNoise);
                if ($landHeightGen > self::LAND_HEIGHT_RANGE) {
                    $canBaseGround = true;
                    $landHeightGen = self::LAND_HEIGHT_RANGE;
                }

                $genyHeight = self::SEA_FLOOR_HEIGHT + $landHeightGen + $mountainGenerate;

                // Determine biome and adjust height for ocean/beach/river
                $biome = self::BIOME_PLAINS;

                if ($genyHeight < self::BEACH_START_HEIGHT) {
                    if ($genyHeight < self::BEACH_START_HEIGHT - 5) {
                        $genyHeight += (int)(self::SEA_FLOOR_GEN_RANGE * $seaFloorNoise[$genz][$genx]);
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
                        $baseGroundHeight2 = (int)(self::BASEGROUND_HEIGHT * ($baseNoise[$genz][$genx] + 1.0));
                        if ($baseGroundHeight2 > $baseGroundHeight) {
                            $baseGroundHeight2 = $baseGroundHeight;
                        }
                        if ($baseGroundHeight2 > $mountainGenerate) {
                            $baseGroundHeight2 -= $mountainGenerate;
                        } else {
                            $baseGroundHeight2 = 0;
                        }
                        $genyHeight += $baseGroundHeight2;
                    }
                }

                if ($canRiver && $genyHeight <= self::SEA_HEIGHT - 5) {
                    $canRiver = false;
                }

                // River carving
                if ($canRiver) {
                    $riverVal = $riverNoise[$genz][$genx];
                    if ($riverVal > -0.25 && $riverVal < 0.25) {
                        $riverVal = $riverVal > 0 ? $riverVal : -$riverVal;
                        $riverVal = 0.25 - $riverVal;
                        $riverVal = $riverVal * $riverVal * 4.0;
                        $riverVal -= 0.0000001;
                        $riverVal = max(0.0, $riverVal);
                        $genyHeight -= (int)($riverVal * 64);
                        if ($genyHeight < self::SEA_HEIGHT) {
                            $biome = self::BIOME_RIVER;
                            if ($genyHeight <= self::SEA_HEIGHT - 8) {
                                $genyHeight1 = self::SEA_HEIGHT - 9 + (int)(self::BASEGROUND_HEIGHT * ($baseNoise[$genz][$genx] + 1.0));
                                $genyHeight2 = $genyHeight < self::SEA_HEIGHT - 7 ? self::SEA_HEIGHT - 7 : $genyHeight;
                                $genyHeight = max($genyHeight1, $genyHeight2);
                            }
                        }
                    }
                }

                $biomes[$genz * 16 + $genx] = $biome;

                // Build column blocks: bedrock, stone core, surface layers, water
                $generateHeight = max($genyHeight, self::SEA_HEIGHT);
                $col = '';
                for ($geny = 0; $geny <= $generateHeight; $geny++) {
                    if ($geny < self::BEDROCK_DEPTH && ($geny === 0 || self::nextRngInt($rngState, 5) === 0)) {
                        $col .= chr(self::BEDROCK);
                    } elseif ($geny > $genyHeight) {
                        if (($biome === self::BIOME_ICE_PLAINS || $biome === self::BIOME_TAIGA) && $geny === self::SEA_HEIGHT) {
                            $col .= chr(self::ICE);
                        } else {
                            $col .= chr(self::STILL_WATER);
                        }
                    } elseif ($geny === $genyHeight) {
                        // Surface layer: grass on land, sand on beaches/desert/ocean
                        if ($biome === self::BIOME_BEACH || $biome === self::BIOME_DESERT || $biome === self::BIOME_OCEAN) {
                            $col .= chr(12); // sand
                        } elseif ($genyHeight >= 96 && ($biome === self::BIOME_MOUNTAINS || $biome === self::BIOME_ICE_PLAINS)) {
                            $col .= chr(12); // sand/gravel on high mountains
                        } else {
                            $col .= chr(2); // grass
                        }
                    } elseif ($geny >= $genyHeight - 3 && $geny < $genyHeight) {
                        // Dirt layer (3 blocks below surface)
                        if ($biome === self::BIOME_BEACH || $biome === self::BIOME_DESERT) {
                            $col .= chr(24); // sandstone below sand
                        } else {
                            $col .= chr(3); // dirt
                        }
                    } else {
                        $col .= chr(self::STONE);
                    }
                }
                $columnBlocks[] = $col;
                $heightmap[$genz * 16 + $genx] = $generateHeight + 1;
            }
        }

        // Transpose per-column profiles into section rows
        $topSectionY = min(7, intdiv(max($heightmap) + 8, 16));
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

        // Cave + ravine carving
        $sections = self::carveCaves($sections, $chunkX, $chunkZ, $seed);
        $sections = self::carveRavines($sections, $chunkX, $chunkZ, $seed);

        return new ChunkData($chunkX, $chunkZ, $sections, $biomes, $heightmap, [], []);
    }

    /**
     * Populate a chunk: ores, ground cover (grass, flowers, etc.).
     */
    public static function populateChunk(int $chunkX, int $chunkZ, ChunkData $data, int $seed): ChunkData {
        $sections = $data->sections;
        $rng = self::seedRng($chunkX, $chunkZ, $seed ^ 0xDEADBEEF);

        // --- Ores ---
        $ores = [
            [self::stoneOreId(16), 20, 16, 0, 128], // coal
            [self::stoneOreId(15), 20, 8, 0, 64],   // iron
            [self::stoneOreId(73), 8, 7, 0, 16],    // redstone
            [self::stoneOreId(21), 1, 6, 0, 32],    // lapis
            [self::stoneOreId(14), 2, 8, 0, 32],    // gold
            [self::stoneOreId(56), 1, 7, 0, 16],    // diamond
        ];

        foreach ($ores as [$oreId, $clusters, $clusterSize, $minY, $maxY]) {
            for ($i = 0; $i < $clusters; $i++) {
                $bx = self::nextRngInt($rng, 16);
                $bz = self::nextRngInt($rng, 16);
                $y = $minY + self::nextRngInt($rng, $maxY - $minY);
                for ($n = 0; $n < $clusterSize; $n++) {
                    $dx = self::nextRngInt($rng, 3) - 1;
                    $dy = self::nextRngInt($rng, 3) - 1;
                    $dz = self::nextRngInt($rng, 3) - 1;
                    $ox = $bx + $dx;
                    $oz = $bz + $dz;
                    $oy = $y + $dy;
                    if ($ox < 0 || $ox > 15 || $oz < 0 || $oz > 15 || $oy < 1 || $oy > 126) {
                        continue;
                    }
                    if (self::readBlock($sections, $ox, $oy, $oz) === self::STONE) {
                        $sections = self::writeBlock($sections, $ox, $oy, $oz, $oreId, false);
                    }
                }
            }
        }

        // --- Dirt veins ---
        for ($i = 0; $i < 20; $i++) {
            $bx = self::nextRngInt($rng, 16);
            $bz = self::nextRngInt($rng, 16);
            $y = self::nextRngInt($rng, 128);
            for ($n = 0; $n < 32; $n++) {
                $dx = self::nextRngInt($rng, 3) - 1;
                $dy = self::nextRngInt($rng, 3) - 1;
                $dz = self::nextRngInt($rng, 3) - 1;
                $ox = $bx + $dx;
                $oz = $bz + $dz;
                $oy = $y + $dy;
                if ($ox < 0 || $ox > 15 || $oz < 0 || $oz > 15 || $oy < 1 || $oy > 126) {
                    continue;
                }
                if (self::readBlock($sections, $ox, $oy, $oz) === self::STONE) {
                    $sections = self::writeBlock($sections, $ox, $oy, $oz, 3, false); // dirt
                }
            }
        }

        // --- Gravel veins ---
        for ($i = 0; $i < 10; $i++) {
            $bx = self::nextRngInt($rng, 16);
            $bz = self::nextRngInt($rng, 16);
            $y = self::nextRngInt($rng, 128);
            for ($n = 0; $n < 16; $n++) {
                $dx = self::nextRngInt($rng, 3) - 1;
                $dy = self::nextRngInt($rng, 3) - 1;
                $dz = self::nextRngInt($rng, 3) - 1;
                $ox = $bx + $dx;
                $oz = $bz + $dz;
                $oy = $y + $dy;
                if ($ox < 0 || $ox > 15 || $oz < 0 || $oz > 15 || $oy < 1 || $oy > 126) {
                    continue;
                }
                if (self::readBlock($sections, $ox, $oy, $oz) === self::STONE) {
                    $sections = self::writeBlock($sections, $ox, $oy, $oz, 13, false); // gravel
                }
            }
        }

        // --- Ground cover: tall grass, flowers, dead bushes, cacti ---
        for ($bz = 0; $bz < 16; $bz++) {
            for ($bx = 0; $bx < 16; $bx++) {
                $biome = $data->biomes[$bz * 16 + $bx] ?? self::BIOME_PLAINS;
                $top = ($data->heightmap[$bz * 16 + $bx] ?? 0) - 1;
                if ($top < 1 || $top > 125) {
                    continue;
                }
                $surfaceBlock = self::readBlock($sections, $bx, $top, $bz);
                // Only place ground cover on grass/dirt/sand
                if ($surfaceBlock !== 2 && $surfaceBlock !== 3 && $surfaceBlock !== 12) {
                    continue;
                }
                if ($top + 1 > 126) {
                    continue;
                }

                $roll = self::nextRngInt($rng, 100);
                if ($biome === self::BIOME_PLAINS || $biome === self::BIOME_FOREST) {
                    if ($roll < 40) {
                        // Tall grass
                        $sections = self::writeBlock($sections, $bx, $top + 1, $bz, 31, false);
                    } elseif ($roll < 43) {
                        // Poppy
                        $sections = self::writeBlock($sections, $bx, $top + 1, $bz, 38, false);
                    } elseif ($roll < 45) {
                        // Dandelion
                        $sections = self::writeBlock($sections, $bx, $top + 1, $bz, 37, false);
                    }
                } elseif ($biome === self::BIOME_DESERT) {
                    if ($roll < 2 && $top + 2 <= 126) {
                        // Cactus (2-3 blocks tall)
                        $height = 2 + self::nextRngInt($rng, 2);
                        for ($cy = 1; $cy <= $height; $cy++) {
                            $sections = self::writeBlock($sections, $bx, $top + $cy, $bz, 81, false);
                        }
                    } elseif ($roll < 4) {
                        // Dead bush
                        $sections = self::writeBlock($sections, $bx, $top + 1, $bz, 32, false);
                    }
                } elseif ($biome === self::BIOME_TAIGA || $biome === self::BIOME_ICE_PLAINS) {
                    if ($roll < 30) {
                        // Tall grass
                        $sections = self::writeBlock($sections, $bx, $top + 1, $bz, 31, false);
                    }
                }
            }
        }

        // --- Trees ---
        // Biome-specific tree placement. Each biome has a density and tree type.
        // Trees are placed on solid ground (grass/dirt), within chunk bounds
        // [3..12] so the canopy fits without cross-chunk writes.
        $treeBiomes = [
            self::BIOME_FOREST => ['type' => 'oak', 'count' => 5],
            self::BIOME_PLAINS => ['type' => 'oak', 'count' => 1],
            self::BIOME_TAIGA => ['type' => 'spruce', 'count' => 3],
            self::BIOME_ICE_PLAINS => ['type' => 'spruce', 'count' => 2],
            self::BIOME_BIRCH_FOREST => ['type' => 'birch', 'count' => 4],
            self::BIOME_SWAMP => ['type' => 'oak', 'count' => 2],
            self::BIOME_MOUNTAINS => ['type' => 'oak', 'count' => 1],
            self::BIOME_SMALL_MOUNTAINS => ['type' => 'oak', 'count' => 1],
        ];

        for ($t = 0; $t < 8; $t++) {
            $tx = 3 + self::nextRngInt($rng, 10);
            $tz = 3 + self::nextRngInt($rng, 10);
            $biome = $data->biomes[$tz * 16 + $tx] ?? self::BIOME_PLAINS;
            $treeConf = $treeBiomes[$biome] ?? null;
            if ($treeConf === null) {
                continue;
            }
            // Density check: not every candidate becomes a tree
            if (self::nextRngInt($rng, 10) >= $treeConf['count']) {
                continue;
            }
            $top = ($data->heightmap[$tz * 16 + $tx] ?? 0) - 1;
            if ($top < 2 || $top > 118) {
                continue;
            }
            $surfaceBlock = self::readBlock($sections, $tx, $top, $tz);
            if ($surfaceBlock !== 2 && $surfaceBlock !== 3) { // grass or dirt only
                continue;
            }
            // Space check: air above the surface
            if (self::readBlock($sections, $tx, $top + 1, $tz) !== 0) {
                continue;
            }
            self::placeTree($sections, $tx, $top, $tz, $treeConf['type'], $rng);
        }

        return new ChunkData($data->chunkX, $data->chunkZ, $sections, $data->biomes, $data->heightmap, [], []);
    }

    /**
     * Place a single tree at (x, baseY, z). Types:
     *  - oak: trunk 4-6, 2x 5x5 canopy layers + 3x3 cap
     *  - birch: trunk 5-7, 2x 5x5 canopy layers + 3x3 cap
     *  - spruce: trunk 6-8, conical 3x3 then 1x1 leaf layers
     */
    private static function placeTree(array &$sections, int $x, int $baseY, int $z, string $type, int &$rng): void {
        $logBlock = 17; // oak log
        $leafBlock = 18; // oak leaves
        $trunkHeight = 4 + self::nextRngInt($rng, 3); // 4-6

        switch ($type) {
            case 'birch':
                $logBlock = 17; // same block, different meta (birch = meta 2)
                $leafBlock = 18; // birch leaves = meta 2
                $trunkHeight = 5 + self::nextRngInt($rng, 3); // 5-7
                break;
            case 'spruce':
                $logBlock = 17; // spruce log = meta 1
                $leafBlock = 18; // spruce leaves = meta 1
                $trunkHeight = 6 + self::nextRngInt($rng, 3); // 6-8
                break;
            default: // oak
                break;
        }

        $topY = $baseY + $trunkHeight;

        if ($type === 'spruce') {
            // Conical spruce: trunk + narrowing leaf layers
            for ($y = $baseY + 1; $y <= $topY; $y++) {
                $sections = self::writeBlock($sections, $x, $y, $z, $logBlock, true);
            }
            // Leaf layers: wide at bottom, narrow at top
            for ($layer = 0; $layer < 4; $layer++) {
                $ly = $topY - 2 + $layer;
                $r = $layer < 2 ? 2 - $layer : 0; // 2, 1, 0, 0
                for ($dx = -$r; $dx <= $r; $dx++) {
                    for ($dz = -$r; $dz <= $r; $dz++) {
                        if ($dx === 0 && $dz === 0) {
                            continue; // trunk space
                        }
                        if (abs($dx) === $r && abs($dz) === $r && $r > 0) {
                            continue; // cut corners
                        }
                        $sections = self::writeBlock($sections, $x + $dx, $ly, $z + $dz, $leafBlock, true);
                    }
                }
            }
            // Top cap
            $sections = self::writeBlock($sections, $x, $topY + 1, $z, $leafBlock, true);
        } else {
            // Oak / Birch: trunk + 2x 5x5 canopy + 3x3 cap
            for ($y = $baseY + 1; $y <= $topY; $y++) {
                $sections = self::writeBlock($sections, $x, $y, $z, $logBlock, true);
            }
            // Two 5x5 layers (minus corners)
            for ($ly = 0; $ly <= 1; $ly++) {
                $y = $topY + $ly;
                for ($dx = -2; $dx <= 2; $dx++) {
                    for ($dz = -2; $dz <= 2; $dz++) {
                        if (abs($dx) === 2 && abs($dz) === 2) {
                            continue;
                        }
                        if ($dx === 0 && $dz === 0 && $ly === 1) {
                            continue; // trunk tip
                        }
                        $sections = self::writeBlock($sections, $x + $dx, $y, $z + $dz, $leafBlock, true);
                    }
                }
            }
            // 3x3 cap
            $y = $topY + 2;
            for ($dx = -1; $dx <= 1; $dx++) {
                for ($dz = -1; $dz <= 1; $dz++) {
                    $sections = self::writeBlock($sections, $x + $dx, $y, $z + $dz, $leafBlock, true);
                }
            }
        }
    }

    // ---- Noise helpers ----

    /**
     * Sample a 16×16 noise grid at chunk coordinates with downsampling factor 4.
     * Returns 16×16 array of values in [-1, 1].
     */
    private static function sampleNoise2D(SimplexNoise $noise, int $chunkX, int $chunkZ, int $seed): array {
        $result = [];
        for ($z = 0; $z < 16; $z++) {
            $result[$z] = [];
            for ($x = 0; $x < 16; $x++) {
                $worldX = ($chunkX * 16 + $x) / 4.0;
                $worldZ = ($chunkZ * 16 + $z) / 4.0;
                $result[$z][$x] = $noise->noise2D($worldX, $worldZ);
            }
        }
        return $result;
    }

    /**
     * Deterministic biome selection using position hash + noise.
     */
    private static function pickBiome(int $x, int $z, int $seed): int {
        $hash = $x * 2345803 ^ $z * 9236449 ^ $seed;
        $hash *= $hash + 223;

        $xNoise = ($hash >> 20) & 3;
        $zNoise = ($hash >> 22) & 3;
        if ($xNoise === 3) $xNoise = 1;
        if ($zNoise === 3) $zNoise = 1;

        // Simple biome selection based on temperature-like hash
        $temp = self::smoothHash($x + $xNoise - 1, $z + $zNoise - 1, $seed ^ 0x4F1BBCDC, 8);
        $humidity = self::smoothHash($x + $xNoise - 1, $z + $zNoise - 1, $seed ^ 0x11D8E2A9, 8);

        $cold = $temp < 24576;
        $hot = $temp > 40960;
        $wet = $humidity > 36000;
        $dry = $humidity < 26214;

        if ($cold) {
            return $wet ? self::BIOME_TAIGA : self::BIOME_ICE_PLAINS;
        }
        if ($hot && $dry) {
            return self::BIOME_DESERT;
        }
        if ($wet) {
            return self::BIOME_FOREST;
        }
        return self::BIOME_PLAINS;
    }

    /**
     * Smooth value noise (same algorithm as ParallelGeneratorAdapter::smoothNoise).
     * Returns [0, 65535].
     */
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

    private static function hash2D(int $x, int $z, int $seed): int {
        $seed &= 0x7FFFFFFF;
        $n = ($x * 374761393) ^ ($z * 668265263) ^ ($seed * 1103515245);
        $n &= 0x7FFFFFFF;
        $n = ($n ^ ($n >> 13)) * 1274126177;
        $n &= 0x7FFFFFFF;
        $n ^= $n >> 16;
        return $n & 0xFFFF;
    }

    private static function hashSeed(int $seed, int $salt): int {
        $n = ($seed ^ ($salt * 0x45D9F3B)) & 0x7FFFFFFF;
        $n = ($n ^ ($n >> 13)) * 1274126177;
        return $n & 0x7FFFFFFF;
    }

    // ---- Cave carving (worm-based, same pattern as ParallelGeneratorAdapter) ----

    private static function carveCaves(array $sections, int $chunkX, int $chunkZ, int $seed): array {
        $rng = self::seedRng($chunkX, $chunkZ, $seed ^ 0x5B4C2A91);

        // Generate 2-4 cave systems per chunk
        $systemCount = 2 + self::nextRngInt($rng, 3);
        for ($s = 0; $s < $systemCount; $s++) {
            $startX = self::nextRngInt($rng, 16);
            $startZ = self::nextRngInt($rng, 16);
            $startY = 8 + self::nextRngInt($rng, 50);
            $radius = 2 + self::nextRngInt($rng, 3);

            $cx = $startX;
            $cy = $startY;
            $cz = $startZ;

            $segments = 8 + self::nextRngInt($rng, 16);
            for ($seg = 0; $seg < $segments; $seg++) {
                // Random walk
                $cx += self::nextRngInt($rng, 5) - 2;
                $cy += self::nextRngInt($rng, 3) - 1;
                $cz += self::nextRngInt($rng, 5) - 2;

                // Clamp to chunk bounds
                $cx = max(1, min(14, $cx));
                $cy = max(5, min(60, $cy));
                $cz = max(1, min(14, $cz));

                // Carve a small sphere
                $r = $radius + self::nextRngInt($rng, 2) - 1;
                for ($dx = -$r; $dx <= $r; $dx++) {
                    for ($dy = -$r; $dy <= $r; $dy++) {
                        for ($dz = -$r; $dz <= $r; $dz++) {
                            if ($dx * $dx + $dy * $dy + $dz * $dz > $r * $r) {
                                continue;
                            }
                            $bx = $cx + $dx;
                            $by = $cy + $dy;
                            $bz = $cz + $dz;
                            if ($bx < 0 || $bx > 15 || $bz < 0 || $bz > 15 || $by < 1 || $by > 126) {
                                continue;
                            }
                            $current = self::readBlock($sections, $bx, $by, $bz);
                            if ($current === self::STONE || $current === 3) { // stone or dirt
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

        // 0-1 ravines per chunk
        if (self::nextRngInt($rng, 3) > 0) {
            return $sections;
        }

        $startX = self::nextRngInt($rng, 16);
        $startZ = self::nextRngInt($rng, 16);
        $startY = 10 + self::nextRngInt($rng, 40);
        $angle = (self::nextRngInt($rng, 360)) * M_PI / 180.0;

        $length = 20 + self::nextRngInt($rng, 40);
        for ($step = 0; $step < $length; $step++) {
            $bx = (int)($startX + cos($angle) * $step);
            $bz = (int)($startZ + sin($angle) * $step);
            $by = $startY + self::nextRngInt($rng, 5) - 2;

            if ($bx < 0 || $bx > 15 || $bz < 0 || $bz > 15) {
                continue;
            }

            // Ravine width: 2-4 blocks
            $width = 2 + self::nextRngInt($rng, 3);
            for ($dx = -$width; $dx <= $width; $dx++) {
                for ($dy = -2; $dy <= 2; $dy++) {
                    $rx = $bx + $dx;
                    $ry = $by + $dy;
                    if ($rx < 0 || $rx > 15 || $ry < 1 || $ry > 126) {
                        continue;
                    }
                    if (abs($dx) === $width && self::nextRngInt($rng, 2) === 0) {
                        continue; // rough edges
                    }
                    $current = self::readBlock($sections, $rx, $ry, $bz);
                    if ($current !== 0 && $current !== self::WATER && $current !== self::STILL_WATER) {
                        $sections = self::writeBlock($sections, $rx, $ry, $bz, 0, false);
                    }
                }
            }

            $angle += (self::nextRngInt($rng, 21) - 10) * 0.01;
        }

        return $sections;
    }

    // ---- Block read/write helpers ----

    private static function readBlock(array $sections, int $x, int $y, int $z): int {
        $sy = intdiv($y, 16);
        if (!isset($sections[$sy])) {
            return 0;
        }
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

    // ---- RNG helpers (deterministic, same pattern as ParallelGeneratorAdapter) ----

    private static function seedRng(int $chunkX, int $chunkZ, int $seed): int {
        $n = ($chunkX * 374761393) ^ ($chunkZ * 668265263) ^ ($seed * 1103515245);
        return $n & 0x7FFFFFFF;
    }

    private static function nextRngInt(int &$state, int $range): int {
        $state = ($state * 1103515245 + 12345) & 0x7FFFFFFF;
        return $range > 0 ? $state % $range : 0;
    }

    private static function stoneOreId(int $id): int {
        return $id;
    }
}

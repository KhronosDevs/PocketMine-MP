<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\worldgen;

use pocketmine\port\driven\ChunkData;
use pocketmine\port\driven\GeneratorConfig;
use pocketmine\port\driven\LightData;
use pocketmine\port\driven\ThreadingPort;
use pocketmine\port\driven\WorldGenPort;
use function array_fill;
use function chr;
use function ord;
use function str_repeat;
use function strlen;

/**
 * Self-contained procedural world generator.
 *
 * Generates flat/terraced terrain directly into ChunkData DTOs — no
 * dependency on legacy Server/Level/Generator classes. The generator
 * type comes from GeneratorConfig (e.g. "flat", "normal").
 */
final class ParallelGeneratorAdapter implements WorldGenPort {
    public const GROUND_BLOCK = 3;   // dirt
    public const STONE_BLOCK = 1;    // stone
    public const GRASS_BLOCK = 2;    // grass
    public const WATER_BLOCK = 8;    // still water
    public const BEDROCK_BLOCK = 7;  // bedrock

    private const SECTION_Y_BLOCKS = 16;
    private const CHUNK_SECTION_COUNT = 16; // 0..15 = y 0..255

    public function __construct(
        private readonly ThreadingPort $threadingPort,
    ) {}

    public function generateChunk(int $chunkX, int $chunkZ, GeneratorConfig $config): ChunkData {
        // For now, generate synchronously; threadingPort is reserved for
        // future parallel population.
        return $this->generateChunkSync($chunkX, $chunkZ, $config);
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

    private function generateChunkSync(int $chunkX, int $chunkZ, GeneratorConfig $config): ChunkData {
        $generatorType = $config->generatorType;
        $seed = $config->seed;

        if ($generatorType === "flat") {
            return $this->generateFlatChunk($chunkX, $chunkZ);
        }

        return $this->generateTerrainChunk($chunkX, $chunkZ, $seed);
    }

    private function generateFlatChunk(int $chunkX, int $chunkZ): ChunkData {
        $surfaceY = 4; // flat world: 3 stone/dirt, grass on top at y=3

        $sections = [];
        // Build sections 0..3 (y 0..63) so the surface sits at y=3.
        for ($sy = 0; $sy <= 3; $sy++) {
            $blocks = str_repeat("\x00", 4096);
            $blockData = str_repeat("\x00", 4096);
            
            for ($i = 0; $i < 4096; $i++) {
                $y = $sy * 16 + intdiv($i, 256);
                $blockId = 0;
                if ($y === 0) {
                    $blockId = self::BEDROCK_BLOCK;
                } elseif ($y < $surfaceY) {
                    $blockId = self::STONE_BLOCK;
                } elseif ($y === $surfaceY) {
                    $blockId = self::GRASS_BLOCK;
                }
                $blocks[$i] = chr($blockId);
            }
            
            $sections[] = [
                'y' => $sy,
                'blocks' => $blocks,
                'data' => $blockData,
                'skyLight' => str_repeat("\xff", 2048),
                'blockLight' => str_repeat("\x00", 2048),
            ];
        }
        
        $biomes = array_fill(0, 256, 1); // plains
        $heightmap = array_fill(0, 256, $surfaceY + 1);
        
        return new ChunkData($chunkX, $chunkZ, $sections, $biomes, $heightmap, [], []);
    }

    private function generateTerrainChunk(int $chunkX, int $chunkZ, int $seed): ChunkData {
        // Simple deterministic pseudo-noise heightmap based on chunk coords + seed.
        $heightmap = [];
        for ($bz = 0; $bz < 16; $bz++) {
            for ($bx = 0; $bx < 16; $bx++) {
                $worldX = $chunkX * 16 + $bx;
                $worldZ = $chunkZ * 16 + $bz;
                $heightmap[$bz * 16 + $bx] = $this->sampleHeight($worldX, $worldZ, $seed);
            }
        }

        $maxHeight = max($heightmap);
        $topSectionY = (int)ceil($maxHeight / 16);

        $sections = [];
        for ($sy = 0; $sy <= $topSectionY; $sy++) {
            $blocks = str_repeat("\x00", 4096);
            
            for ($i = 0; $i < 4096; $i++) {
                $y = $sy * 16 + intdiv($i, 256);
                $height = $heightmap[$i % 256];
                $blockId = 0;
                if ($y === 0) {
                    $blockId = self::BEDROCK_BLOCK;
                } elseif ($y < $height - 3) {
                    $blockId = self::STONE_BLOCK;
                } elseif ($y < $height) {
                    $blockId = self::GROUND_BLOCK;
                } elseif ($y === $height) {
                    $blockId = self::GRASS_BLOCK;
                } elseif ($y <= $height + 3) {
                    $blockId = self::WATER_BLOCK;
                }
                $blocks[$i] = chr($blockId);
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

    private function sampleHeight(int $x, int $z, int $seed): int {
        // 2D value-noise approximation using integer hashing.
        $n = ($x * 374761393 + $z * 668265263 + $seed * 1442695040888963407) & 0x7FFFFFFF;
        $h1 = (($n >> 16) ^ $n) * 0x45D9F3B;
        $h2 = (($h1 >> 16) ^ $h1) * 0x45D9F3B;
        $v = (($h2 >> 16) ^ $h2) & 0x7FFFFFFF;
        
        // Combine two octaves for gentle hills.
        $coarse = $this->hash01(intdiv($x, 8), intdiv($z, 8), $seed);
        $fine = $this->hash01(intdiv($x, 2), intdiv($z, 2), $seed ^ 0x9E3779B9);
        
        $height = 64 + (int)($coarse * 24) + (int)($fine * 8);
        return max(2, min(120, $height));
    }

    private function hash01(int $x, int $z, int $seed): float {
        $n = ($x * 73856093) ^ ($z * 19349663) ^ ($seed * 83492791);
        $n = (($n >> 16) ^ $n) * 0x45D9F3B;
        $n = (($n >> 16) ^ $n) * 0x45D9F3B;
        $n = ($n >> 16) ^ $n;
        return ($n & 0xFFFF) / 65535.0;
    }
}

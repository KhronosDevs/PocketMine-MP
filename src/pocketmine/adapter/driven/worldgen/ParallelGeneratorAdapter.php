<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\worldgen;

use pocketmine\level\generator\Generator;
use pocketmine\level\Level;
use pocketmine\port\driven\ChunkData;
use pocketmine\port\driven\GeneratorConfig;
use pocketmine\port\driven\LightData;
use pocketmine\port\driven\ThreadingPort;
use pocketmine\port\driven\WorldGenPort;
use pocketmine\Server;

final class ParallelGeneratorAdapter implements WorldGenPort {
    public function __construct(
        private readonly ThreadingPort $threadingPort,
    ) {}

    public function generateChunk(int $chunkX, int $chunkZ, GeneratorConfig $config): ChunkData {
        // Synchronous generation for now - can be made async via threadingPort
        $level = $this->getLevelForConfig($config);
        if (!$level) {
            return new ChunkData($chunkX, $chunkZ, [], [], [], [], []);
        }

        $generator = $level->getGenerator();
        if (!$generator) {
            return new ChunkData($chunkX, $chunkZ, [], [], [], [], []);
        }

        // Generate chunk synchronously
        $chunk = $generator->generateChunk($chunkX, $chunkZ);
        
        // Convert FullChunk to ChunkData
        return $this->fullChunkToChunkData($chunk, $chunkX, $chunkZ);
    }

    public function populateChunk(int $chunkX, int $chunkZ, ChunkData $data): void {
        // This would populate the chunk with structures, ores, etc.
        // For now, we delegate to the level's generator
    }

    public function calculateLight(int $chunkX, int $chunkZ, ChunkData $data): LightData {
        // Calculate sky light and block light for the chunk
        // This is computationally intensive and should be done in parallel
        return new LightData([], []);
    }

    private function getLevelForConfig(GeneratorConfig $config): ?Level {
        // In practice, this would be passed from the caller
        // For now, try to get the default level
        $server = Server::getInstance();
        return $server->getDefaultLevel();
    }

    private function fullChunkToChunkData(\pocketmine\level\format\FullChunk $chunk, int $chunkX, int $chunkZ): ChunkData {
        $sections = [];
        foreach ($chunk->getSections() as $section) {
            if (!($section instanceof \pocketmine\level\format\generic\EmptyChunkSection)) {
                $sections[] = [
                    'y' => $section->getY(),
                    'blocks' => $section->getBlockIdArray(),
                    'data' => $section->getBlockDataArray(),
                    'skyLight' => $section->getBlockSkyLightArray(),
                    'blockLight' => $section->getBlockLightArray(),
                ];
            }
        }

        $biomes = $chunk->getBiomeColorArray();
        if (count($biomes) !== 256) {
            $biomes = array_pad($biomes, 256, 0);
        }

        $heightmap = $chunk->getHeightMapArray();
        if (count($heightmap) !== 256) {
            $heightmap = array_pad($heightmap, 256, 0);
        }

        $entities = [];
        foreach ($chunk->getEntities() as $entity) {
            // Would serialize entity here
        }

        $tileEntities = [];
        foreach ($chunk->getTiles() as $tile) {
            // Would serialize tile entity here
        }

        return new ChunkData($chunkX, $chunkZ, $sections, $biomes, $heightmap, $entities, $tileEntities);
    }
}
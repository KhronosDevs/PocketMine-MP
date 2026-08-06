<?php

declare(strict_types=1);

namespace pocketmine\domain\service;

use pocketmine\domain\ecs\World;
use pocketmine\port\driven\ChunkData;
use pocketmine\port\driven\StoragePort;
use pocketmine\port\driven\WorldGenPort;
use pocketmine\port\driven\LightData;

final class ChunkLoadService {
    private const MAX_LOADED_CHUNKS = 10000;

    public function __construct(
        private readonly World $world,
        private readonly StoragePort $storagePort,
        private readonly WorldGenPort $worldGenPort,
    ) {}

    public function loadChunk(int $chunkX, int $chunkZ): ChunkData {
        // Try to load from storage
        $chunkData = $this->storagePort->loadChunk($chunkX, $chunkZ);
        
        // If chunk doesn't exist, generate it
        if ($this->isEmptyChunk($chunkData)) {
            $chunkData = $this->generateChunk($chunkX, $chunkZ);
        }
        
        // Populate if needed
        if (!$this->isPopulated($chunkData)) {
            $this->worldGenPort->populateChunk($chunkX, $chunkZ, $chunkData);
        }
        
        // Calculate light if needed
        if (!$this->hasLightData($chunkData)) {
            $lightData = $this->worldGenPort->calculateLight($chunkX, $chunkZ, $chunkData);
            // Apply light data to chunk
        }
        
        return $chunkData;
    }

    public function loadChunkWithContext(\pocketmine\level\Level $level, int $chunkX, int $chunkZ): ChunkData {
        return $this->storagePort->loadChunkWithContext($level, $chunkX, $chunkZ);
    }

    private function generateChunk(int $chunkX, int $chunkZ): ChunkData {
        $config = new \pocketmine\port\driven\GeneratorConfig(
            'normal', // default generator
            $this->getWorldSeed(),
            []
        );
        
        return $this->worldGenPort->generateChunk($chunkX, $chunkZ, $config);
    }

    private function getWorldSeed(): int {
        $server = \pocketmine\Server::getInstance();
        $level = $server?->getDefaultLevel();
        return $level?->getSeed() ?? random_int(1, PHP_INT_MAX);
    }

    private function isEmptyChunk(ChunkData $data): bool {
        return empty($data->sections) && empty($data->entities) && empty($data->tileEntities);
    }

    private function isPopulated(ChunkData $data): bool {
        // Check if chunk has been populated (has structures, ores, etc.)
        // For now, assume not populated if no tile entities or special blocks
        return !empty($data->tileEntities);
    }

    private function hasLightData(ChunkData $data): bool {
        foreach ($data->sections as $section) {
            if (!empty($section['skyLight']) || !empty($section['blockLight'])) {
                return true;
            }
        }
        return false;
    }

    public function unloadChunk(int $chunkX, int $chunkZ): void {
        // Save chunk to storage
        // In practice, this would be called with the chunk data from the world
    }

    public function saveChunk(ChunkData $data): void {
        $this->storagePort->saveChunk($data->chunkX, $data->chunkZ, $data);
    }

    public function saveChunkWithContext(\pocketmine\level\Level $level, ChunkData $data): void {
        $this->storagePort->saveChunkWithContext($level, $data);
    }
}
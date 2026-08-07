<?php

declare(strict_types=1);

namespace pocketmine\core\service;

use pocketmine\core\ecs\World;
use pocketmine\core\resource\ChunkStore;
use pocketmine\port\driven\ChunkData;
use pocketmine\port\driven\StoragePort;
use pocketmine\port\driven\WorldGenPort;

final class ChunkLoadService {
    private const MAX_LOADED_CHUNKS = 10000;

    public function __construct(
        private readonly World $world,
        private readonly StoragePort $storagePort,
        private readonly WorldGenPort $worldGenPort,
    ) {}

    public function loadChunk(int $chunkX, int $chunkZ): ChunkData {
        return $this->loadChunks([[$chunkX, $chunkZ]])[0];
    }

    /**
     * Bulk-load a set of chunks, generating any missing ones in a single
     * parallel WorldGenPort::generateChunks() call (the pool runs them across
     * worker threads) instead of one serialized generateChunk() per chunk.
     *
     * @param array<int, array{0: int, 1: int}> $chunkCoords chunk coordinate pairs
     * @return list<ChunkData> one per requested coord, in input order
     */
    public function loadChunks(array $chunkCoords): array {
        if (empty($chunkCoords)) {
            return [];
        }
        $config = new \pocketmine\port\driven\GeneratorConfig(
            'normal', // default generator
            $this->getWorldSeed(),
            []
        );

        // Split into stored (already on disk) vs missing (need generation).
        /** @var list<array{0: int, 1: int}> $toGenerate */
        $toGenerate = [];
        /** @var array<int, ?ChunkData> $byIndex */
        $byIndex = [];
        foreach ($chunkCoords as $index => [$chunkX, $chunkZ]) {
            $chunkData = $this->storagePort->loadChunk($chunkX, $chunkZ);
            if ($this->isEmptyChunk($chunkData)) {
                $toGenerate[] = [$chunkX, $chunkZ];
                $byIndex[$index] = null;
            } else {
                $byIndex[$index] = $chunkData;
            }
        }

        if (!empty($toGenerate)) {
            $generated = $this->worldGenPort->generateChunks($toGenerate, $config);
            $gi = 0;
            foreach ($byIndex as $index => $data) {
                if ($data === null) {
                    $byIndex[$index] = $generated[$gi];
                    $gi++;
                }
            }
        }

        // Populate, light, and materialize each chunk into the in-memory store.
        $result = [];
        ksort($byIndex);
        foreach ($byIndex as $index => $chunkData) {
            if (!$chunkData instanceof ChunkData) {
                continue; // defensive: every index was resolved above
            }
            $result[$index] = $this->materializeChunk($chunkData);
        }
        return array_values($result);
    }

    private function materializeChunk(ChunkData $chunkData): ChunkData {
        $chunkX = $chunkData->chunkX;
        $chunkZ = $chunkData->chunkZ;

        // Populate if needed
        if (!$this->isPopulated($chunkData)) {
            $this->worldGenPort->populateChunk($chunkX, $chunkZ, $chunkData);
        }
        
        // Calculate light if needed
        if (!$this->hasLightData($chunkData)) {
            $lightData = $this->worldGenPort->calculateLight($chunkX, $chunkZ, $chunkData);
            // Apply light data to chunk
        }
        
        // Materialize the chunk into the in-memory store so block reads/writes
        // and the API World facade operate on real data.
        $store = $this->getChunkStore();
        if ($store !== null) {
            $store->load($chunkData);
            if (!$this->isEmptyChunk($chunkData)) {
                $store->markGenerated($chunkX, $chunkZ);
                // Generated chunks are populated once the population pass ran
                // (for generators whose population is a no-op, this is still true
                // because the pass executed).
                $store->markPopulated($chunkX, $chunkZ);
            }
        }
        
        return $chunkData;
    }

    private function getWorldSeed(): int {
        $kernel = \pocketmine\Kernel::getInstance();
        if ($kernel !== null) {
            $resource = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ServerConfig::class);
            if ($resource !== null) {
                return $resource->getSeed();
            }
        }
        return random_int(1, PHP_INT_MAX);
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
        // Save the in-memory chunk to storage, then drop it from the store.
        $store = $this->getChunkStore();
        if ($store !== null && $store->isLoaded($chunkX, $chunkZ)) {
            $chunkData = $store->toChunkData($chunkX, $chunkZ);
            if ($chunkData !== null) {
                $this->storagePort->saveChunk($chunkX, $chunkZ, $chunkData);
            }
            $store->unload($chunkX, $chunkZ);
        }
    }

    public function saveChunk(ChunkData $data): void {
        $store = $this->getChunkStore();
        // Never overwrite newer in-memory state with an older DTO: only import
        // the DTO when the chunk is not currently loaded.
        if ($store !== null && !$store->isLoaded($data->chunkX, $data->chunkZ)) {
            $store->load($data);
        }
        $this->storagePort->saveChunk($data->chunkX, $data->chunkZ, $data);
    }

    private function getChunkStore(): ?ChunkStore {
        $store = $this->world->getResourceRegistry()->get(ChunkStore::class);
        return $store instanceof ChunkStore ? $store : null;
    }
}
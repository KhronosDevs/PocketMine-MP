<?php

declare(strict_types=1);

namespace pocketmine\port\driven;

interface WorldGenPort {
    public function generateChunk(int $chunkX, int $chunkZ, GeneratorConfig $config): ChunkData;

    public function populateChunk(int $chunkX, int $chunkZ, ChunkData $data): void;

    public function calculateLight(int $chunkX, int $chunkZ, ChunkData $data): LightData;
}
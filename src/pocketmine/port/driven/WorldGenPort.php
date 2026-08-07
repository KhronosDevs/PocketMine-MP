<?php

declare(strict_types=1);

namespace pocketmine\port\driven;

interface WorldGenPort {
    public function generateChunk(int $chunkX, int $chunkZ, GeneratorConfig $config): ChunkData;

    /**
     * Generate many chunks in one call. Implementations may dispatch the work
     * across worker threads in parallel; results must be returned in input
     * order and be identical to calling generateChunk() per chunk.
     *
     * @param array<int, array{0: int, 1: int}> $chunks chunk coordinate pairs
     * @return list<ChunkData> one per input, in input order
     */
    public function generateChunks(array $chunks, GeneratorConfig $config): array;

    public function populateChunk(int $chunkX, int $chunkZ, ChunkData $data): void;

    public function calculateLight(int $chunkX, int $chunkZ, ChunkData $data): LightData;
}
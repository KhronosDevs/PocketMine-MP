<?php

declare(strict_types=1);

namespace pocketmine\port\driven;

interface StoragePort {
    public function loadChunk(int $chunkX, int $chunkZ): ChunkData;

    public function saveChunk(int $chunkX, int $chunkZ, ChunkData $data): void;

    public function loadEntity(string $entityId): EntitySnapshot;

    public function saveEntity(EntitySnapshot $snapshot): void;

    public function saveAll(): void;
}
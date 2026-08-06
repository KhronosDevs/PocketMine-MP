<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\storage;

use pocketmine\port\driven\ChunkData;
use pocketmine\port\driven\EntitySnapshot;
use pocketmine\port\driven\StoragePort;
use pocketmine\port\driven\TileEntitySnapshot;

final class AnvilStorageAdapter implements StoragePort {
    public function loadChunk(int $chunkX, int $chunkZ): ChunkData {
        // TODO: Implement Anvil chunk loading
        return new ChunkData($chunkX, $chunkZ, [], [], [], [], []);
    }

    public function saveChunk(int $chunkX, int $chunkZ, ChunkData $data): void {
        // TODO: Implement Anvil chunk saving
    }

    public function loadEntity(string $entityId): EntitySnapshot {
        // TODO: Implement entity loading
        return new EntitySnapshot($entityId, '', 0, 0, 0, 0, 0, []);
    }

    public function saveEntity(EntitySnapshot $snapshot): void {
        // TODO: Implement entity saving
    }

    public function saveAll(): void {
        // TODO: Implement bulk save
    }
}
<?php

declare(strict_types=1);

namespace pocketmine\port\driven;

interface StoragePort {
    public function loadChunk(int $chunkX, int $chunkZ): ChunkData;

    public function saveChunk(int $chunkX, int $chunkZ, ChunkData $data): void;

    public function loadEntity(string $entityId): EntitySnapshot;

    public function saveEntity(EntitySnapshot $snapshot): void;

    /**
     * 14.4: load the persisted world meta (seed, spawn, difficulty, time) or
     * null when no world has been saved yet.
     *
     * @return array<string, string>|null string-keyed meta values
     */
    public function loadWorldMeta(): ?array;

    /**
     * 14.20b: does the world folder exist on disk? A fresh server has no
     * folder yet; a dropped-in world folder (foreign data, no Khronos
     * level.dat) does. Used to default unknown worlds to the void generator.
     */
    public function worldFolderExists(): bool;

    /**
     * 14.4: persist the world meta (seed, spawn, difficulty, time) so a
     * restart reproduces the same terrain and spawn point.
     *
     * @param array<string, string> $meta string-keyed meta values
     */
    public function saveWorldMeta(array $meta): void;

    public function saveAll(): void;
}
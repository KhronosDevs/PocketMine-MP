<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\storage;

use pocketmine\port\driven\ChunkData;
use pocketmine\port\driven\EntitySnapshot;
use pocketmine\port\driven\StoragePort;
use pocketmine\port\driven\TileEntitySnapshot;
use pocketmine\utils\BinaryStream;
use function ceil;
use function chr;
use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function fopen;
use function fread;
use function fseek;
use function ftell;
use function fwrite;
use function fclose;
use function gzcompress;
use function gzuncompress;
use function is_dir;
use function mkdir;
use function ord;
use function pack;
use function str_repeat;
use function time;
use function unpack;

/**
 * Region-based (Anvil/MCA) chunk storage adapter.
 *
 * Serializes ChunkData/EntitySnapshot DTOs directly — no dependency on
 * legacy level/entity/NBT classes. The on-disk format is a simplified
 * region format with a 8192-byte header (sector offsets + timestamps)
 * followed by zlib-compressed chunk payloads.
 */
final class AnvilStorageAdapter implements StoragePort {
    private const SECTOR_SIZE = 4096;
    private const HEADER_SIZE = 8192;

    private string $basePath;
    private string $levelName = "world";

    public function __construct(string $dataPath = "", string $levelName = "world") {
        $this->basePath = $dataPath !== "" ? $dataPath : "worlds/";
        $this->levelName = $levelName;
        
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0755, true);
        }
    }

    public function loadChunk(int $chunkX, int $chunkZ): ChunkData {
        $regionFile = $this->getRegionFile($chunkX, $chunkZ);
        
        if (!$regionFile || !file_exists($regionFile)) {
            return $this->generateEmptyChunk($chunkX, $chunkZ);
        }

        $raw = $this->readChunkFromRegion($regionFile, $chunkX, $chunkZ);
        
        if ($raw === null || $raw === "") {
            return $this->generateEmptyChunk($chunkX, $chunkZ);
        }

        $parsed = $this->parseChunkData($raw, $chunkX, $chunkZ);
        return $parsed ?? $this->generateEmptyChunk($chunkX, $chunkZ);
    }

    public function saveChunk(int $chunkX, int $chunkZ, ChunkData $data): void {
        $regionFile = $this->getRegionFile($chunkX, $chunkZ);
        
        if (!$regionFile) {
            return;
        }

        $chunkBytes = $this->serializeChunkData($data);
        $this->writeChunkToRegion($regionFile, $chunkX, $chunkZ, $chunkBytes);
    }

    public function loadEntity(string $entityId): EntitySnapshot {
        return new EntitySnapshot($entityId, '', 0.0, 0.0, 0.0, 0.0, 0.0, []);
    }

    public function saveEntity(EntitySnapshot $snapshot): void {
        // Entity persistence is handled via chunk snapshots for now.
    }

    /** Magic + version header of the level.dat-style world meta file. */
    private const WORLD_META_MAGIC = 0x4B524F4E; // 'KRON'
    private const WORLD_META_VERSION = 1;

    /** The per-world folder (basePath + levelName + '/'). */
    private function worldFolder(): string {
        return $this->basePath . $this->levelName . '/';
    }

    public function loadWorldMeta(): ?array {
        $file = $this->worldFolder() . 'level.dat';
        if (!file_exists($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }
        $stream = new BinaryStream($raw);
        if ($stream->getInt() !== self::WORLD_META_MAGIC) {
            return null; // not a file this adapter wrote
        }
        if ($stream->getByte() !== self::WORLD_META_VERSION) {
            return null;
        }
        $count = $stream->getByte();
        $meta = [];
        for ($i = 0; $i < $count; $i++) {
            $key = (string)$stream->getString();
            $value = (string)$stream->getString();
            $meta[$key] = $value;
        }
        return $meta;
    }

    public function saveWorldMeta(array $meta): void {
        $dir = $this->worldFolder();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $stream = new BinaryStream();
        $stream->putInt(self::WORLD_META_MAGIC);
        $stream->putByte(self::WORLD_META_VERSION);
        $stream->putByte(count($meta));
        foreach ($meta as $key => $value) {
            $stream->putString((string)$key);
            $stream->putString((string)$value);
        }
        file_put_contents($dir . 'level.dat', $stream->getBuffer());
    }

    public function saveAll(): void {
        // Region files are written through on chunk save; nothing to flush.
    }

    private function getRegionFile(int $chunkX, int $chunkZ): string {
        $worldFolder = $this->basePath . $this->levelName . "/";
        $regionDir = $worldFolder . "region/";
        
        if (!is_dir($regionDir)) {
            mkdir($regionDir, 0755, true);
        }

        $regionX = $chunkX >> 5;
        $regionZ = $chunkZ >> 5;
        
        $regionFile = $regionDir . "r.{$regionX}.{$regionZ}.mca";
        
        if (!file_exists($regionFile)) {
            $this->createRegionFile($regionFile);
        }
        
        return $regionFile;
    }

    private function createRegionFile(string $path): void {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        $header = str_repeat("\x00", self::HEADER_SIZE);
        file_put_contents($path, $header);
    }

    private function readChunkFromRegion(string $regionFile, int $chunkX, int $chunkZ): ?string {
        $localX = $chunkX & 31;
        $localZ = $chunkZ & 31;
        $index = ($localZ * 32 + $localX) * 4;
        
        $handle = fopen($regionFile, "rb");
        if (!$handle) {
            return null;
        }
        
        fseek($handle, $index);
        $header = fread($handle, 4);
        fclose($handle);
        
        if ($header === false || strlen($header) < 4) {
            return null;
        }
        
        $offsetAndSize = unpack("N", $header)[1];
        $sectorOffset = ($offsetAndSize >> 8) & 0xFFFFFF;
        $sectorCount = $offsetAndSize & 0xFF;
        
        if ($sectorOffset === 0 || $sectorCount === 0) {
            return null;
        }
        
        $handle = fopen($regionFile, "rb");
        if (!$handle) {
            return null;
        }
        fseek($handle, $sectorOffset * self::SECTOR_SIZE);
        $lengthData = fread($handle, 4);
        if ($lengthData === false || strlen($lengthData) < 4) {
            fclose($handle);
            return null;
        }
        $length = unpack("N", $lengthData)[1];
        // Guard against corrupt headers: a single chunk may span at most a few
        // sectors (each 4096 bytes); cap the allocation to avoid OOM on garbage.
        if ($length < 1 || $length > 256 * self::SECTOR_SIZE) {
            fclose($handle);
            return null;
        }
        $compression = ord(fread($handle, 1));
        $data = fread($handle, max(0, $length - 1));
        fclose($handle);
        
        // Only zlib (type 2) is written by this adapter; anything else is a
        // corrupt or foreign file, so treat it as missing rather than parse
        // still-compressed bytes as a chunk payload.
        if ($compression !== 2 || $data === false) {
            return null;
        }
        $decompressed = gzuncompress($data);
        return $decompressed !== false ? $decompressed : null;
    }

    private function writeChunkToRegion(string $regionFile, int $chunkX, int $chunkZ, string $data): void {
        $localX = $chunkX & 31;
        $localZ = $chunkZ & 31;
        $index = ($localZ * 32 + $localX) * 4;
        
        $compressed = gzcompress($data);
        if ($compressed === false) {
            return;
        }
        $length = strlen($compressed) + 1;
        // The sector must hold the 4-byte length prefix too; sizing from
        // $length alone would under-count exactly when $length crosses a
        // 4096 boundary, making the padding below negative and crashing
        // str_repeat() on a legitimately-sized chunk.
        $sectorCount = (int)ceil(($length + 4) / self::SECTOR_SIZE);
        
        $handle = fopen($regionFile, "r+b");
        if (!$handle) {
            return;
        }
        
        fseek($handle, 0, SEEK_END);
        $fileSize = ftell($handle);
        $sectorOffset = (int)ceil($fileSize / self::SECTOR_SIZE);
        
        fseek($handle, $sectorOffset * self::SECTOR_SIZE);
        fwrite($handle, pack("N", $length));
        fwrite($handle, chr(2));
        fwrite($handle, $compressed);
        
        $padding = $sectorCount * self::SECTOR_SIZE - $length - 4;
        if ($padding > 0) {
            fwrite($handle, str_repeat("\x00", $padding));
        }
        
        fseek($handle, $index);
        $offsetAndSize = ($sectorOffset << 8) | $sectorCount;
        fwrite($handle, pack("N", $offsetAndSize));
        
        // Timestamp table lives in the SECOND half of the header (bytes
        // 4096..8191). Using HEADER_SIZE (8192) as the base would write the
        // first timestamp over the first chunk's data sector, corrupting the
        // length field on every save.
        fseek($handle, self::HEADER_SIZE / 2 + ($localZ * 32 + $localX) * 4);
        fwrite($handle, pack("N", time()));
        
        fclose($handle);
    }

    private function parseChunkData(string $data, int $chunkX, int $chunkZ): ?ChunkData {
        $stream = new BinaryStream($data);
        
        $version = $stream->getByte();
        if ($version !== 1) {
            return null;
        }
        
        $sections = [];
        $sectionCount = $stream->getByte();
        
        for ($i = 0; $i < $sectionCount; $i++) {
            $y = $stream->getByte();
            $blocks = $stream->get(4096);
            $blockData = $stream->get(4096);
            $skyLight = $stream->get(2048);
            $blockLight = $stream->get(2048);
            
            $sections[] = [
                'y' => $y,
                'blocks' => $blocks,
                'data' => $blockData,
                'skyLight' => $skyLight,
                'blockLight' => $blockLight,
            ];
        }
        
        $biomes = [];
        for ($i = 0; $i < 256; $i++) {
            $biomes[] = $stream->getByte();
        }
        
        $heightmap = [];
        for ($i = 0; $i < 256; $i++) {
            $heightmap[] = $stream->getInt();
        }
        
        $entities = [];
        $entityCount = $stream->getInt();
        for ($i = 0; $i < $entityCount; $i++) {
            $entities[] = $this->readEntity($stream);
        }
        
        $tileEntities = [];
        $tileCount = $stream->getInt();
        for ($i = 0; $i < $tileCount; $i++) {
            $tileEntities[] = $this->readTileEntity($stream);
        }
        
        return new ChunkData($chunkX, $chunkZ, $sections, $biomes, $heightmap, $entities, $tileEntities);
    }

    private function readEntity(BinaryStream $stream): EntitySnapshot {
        $entityId = $stream->getString();
        $className = $stream->getString();
        $x = $stream->getDouble();
        $y = $stream->getDouble();
        $z = $stream->getDouble();
        $yaw = $stream->getFloat();
        $pitch = $stream->getFloat();
        
        $componentCount = $stream->getInt();
        $components = [];
        for ($i = 0; $i < $componentCount; $i++) {
            $type = $stream->getString();
            $componentData = $stream->getString();
            $components[$type] = $componentData;
        }
        
        return new EntitySnapshot(
            (string)$entityId,
            $className,
            $x, $y, $z,
            $yaw, $pitch,
            $components
        );
    }

    private function readTileEntity(BinaryStream $stream): TileEntitySnapshot {
        $id = $stream->getString();
        $className = $stream->getString();
        $x = $stream->getInt();
        $y = $stream->getInt();
        $z = $stream->getInt();
        
        $dataLength = $stream->getInt();
        $data = $stream->get($dataLength);
        
        return new TileEntitySnapshot(
            (string)$id,
            $className,
            $x, $y, $z,
            ['nbt' => base64_encode($data)]
        );
    }

    private function serializeChunkData(ChunkData $data): string {
        $stream = new BinaryStream();
        
        $stream->putByte(1);
        
        $stream->putByte(count($data->sections));
        foreach ($data->sections as $section) {
            $stream->putByte($section['y']);
            $stream->put($section['blocks']);
            $stream->put($section['data'] ?? str_repeat("\x00", 4096));
            $stream->put($section['skyLight'] ?? str_repeat("\x00", 2048));
            $stream->put($section['blockLight'] ?? str_repeat("\x00", 2048));
        }
        
        foreach ($data->biomes as $biome) {
            $stream->putByte($biome);
        }
        
        foreach ($data->heightmap as $height) {
            $stream->putInt($height);
        }
        
        $stream->putInt(count($data->entities));
        foreach ($data->entities as $entity) {
            $stream->putString($entity->id);
            $stream->putString($entity->type);
            $stream->putDouble($entity->x);
            $stream->putDouble($entity->y);
            $stream->putDouble($entity->z);
            $stream->putFloat($entity->yaw);
            $stream->putFloat($entity->pitch);
            
            $stream->putInt(count($entity->components));
            foreach ($entity->components as $type => $componentData) {
                $stream->putString($type);
                $stream->putString($componentData);
            }
        }
        
        $stream->putInt(count($data->tileEntities));
        foreach ($data->tileEntities as $tile) {
            $stream->putString($tile->id);
            $stream->putString($tile->type);
            $stream->putInt($tile->x);
            $stream->putInt($tile->y);
            $stream->putInt($tile->z);
            
            $nbtData = base64_decode($tile->data['nbt'] ?? '');
            $stream->putInt(strlen($nbtData));
            $stream->put($nbtData);
        }
        
        return $stream->getBuffer();
    }

    private function generateEmptyChunk(int $chunkX, int $chunkZ): ChunkData {
        return new ChunkData($chunkX, $chunkZ, [], array_fill(0, 256, 0), array_fill(0, 256, 0), [], []);
    }
}

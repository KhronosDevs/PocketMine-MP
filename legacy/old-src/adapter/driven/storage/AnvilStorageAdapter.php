<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\storage;

use pocketmine\level\format\FullChunk;
use pocketmine\level\Level;
use pocketmine\math\Vector3;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\ByteArrayTag;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\IntArrayTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\LongTag;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\port\driven\ChunkData;
use pocketmine\port\driven\EntitySnapshot;
use pocketmine\port\driven\StoragePort;
use pocketmine\port\driven\TileEntitySnapshot;
use pocketmine\Server;
use pocketmine\utils\Binary;
use function file_exists;
use function glob;
use function is_dir;
use function mkdir;
use function gzcompress;
use function gzuncompress;
use function count;
use function ord;
use function chr;
use function pack;
use function unpack;

final class AnvilStorageAdapter implements StoragePort {
    private string $basePath;
    private array $levels = [];

    public function __construct(string $dataPath = "") {
        $server = Server::getInstance();
        $this->basePath = $dataPath !== "" ? $dataPath : ($server ? $server->getDataPath() . "worlds/" : "worlds/");
        
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0755, true);
        }
    }

    public function loadChunk(int $chunkX, int $chunkZ): ChunkData {
        // This needs a Level context to know which world
        return new ChunkData($chunkX, $chunkZ, [], [], [], [], []);
    }

    public function saveChunk(int $chunkX, int $chunkZ, ChunkData $data): void {
        // This needs a Level context
    }

    public function loadEntity(string $entityId): EntitySnapshot {
        return new EntitySnapshot($entityId, '', 0, 0, 0, 0, 0, []);
    }

    public function saveEntity(EntitySnapshot $snapshot): void {
        // Implementation would save entity to level.dat or chunk
    }

    public function saveAll(): void {
        $server = Server::getInstance();
        if (!$server) return;
        
        foreach ($server->getLevels() as $level) {
            if ($level->getAutoSave()) {
                $this->saveLevel($level);
            }
        }
    }

    public function loadChunkWithContext(Level $level, int $chunkX, int $chunkZ): ChunkData {
        $regionFile = $this->getRegionFile($level, $chunkX, $chunkZ);
        
        if (!$regionFile) {
            return $this->generateEmptyChunk($chunkX, $chunkZ);
        }

        $chunkData = $this->readChunkFromRegion($regionFile, $chunkX, $chunkZ);
        
        if ($chunkData === null) {
            return $this->generateEmptyChunk($chunkX, $chunkZ);
        }

        return $this->parseChunkData($chunkData, $chunkX, $chunkZ);
    }

    public function saveChunkWithContext(Level $level, ChunkData $data): void {
        $regionFile = $this->getRegionFile($level, $data->chunkX, $data->chunkZ);
        
        if (!$regionFile) {
            return;
        }

        $chunkBytes = $this->serializeChunkData($data);
        $this->writeChunkToRegion($regionFile, $data->chunkX, $data->chunkZ, $chunkBytes);
    }

    public function loadEntityWithContext(Level $level, string $entityId): ?EntitySnapshot {
        // Entities are stored in level.dat or chunk data
        // For now, return null
        return null;
    }

    public function saveEntityWithContext(Level $level, EntitySnapshot $snapshot): void {
        // Save entity to level.dat entities list
    }

    private function getRegionFile(Level $level, int $chunkX, int $chunkZ): ?string {
        $worldFolder = $this->basePath . $level->getFolderName() . "/";
        $regionDir = $worldFolder . "region/";
        
        if (!is_dir($regionDir)) {
            return null;
        }

        $regionX = $chunkX >> 5; // 32 chunks per region
        $regionZ = $chunkZ >> 5;
        
        $regionFile = $regionDir . "r.{$regionX}.{$regionZ}.mca";
        
        if (!file_exists($regionFile)) {
            // Create empty region file
            $this->createRegionFile($regionFile);
        }
        
        return $regionFile;
    }

    private function createRegionFile(string $path): void {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        // Create empty region file (8192 bytes header + 1024 * 4096 bytes sectors)
        $header = str_repeat("\x00", 8192);
        file_put_contents($path, $header);
    }

    private function readChunkFromRegion(string $regionFile, int $chunkX, int $chunkZ): ?string {
        $localX = $chunkX & 31;
        $localZ = $chunkZ & 31;
        $index = ($localZ * 32 + $localX) * 4;
        
        $handle = fopen($regionFile, "rb");
        if (!$handle) return null;
        
        fseek($handle, $index);
        $header = fread($handle, 4);
        fclose($handle);
        
        if ($header === false || strlen($header) < 4) return null;
        
        $offsetAndSize = unpack("N", $header)[1];
        $sectorOffset = ($offsetAndSize >> 8) & 0xFFFFFF;
        $sectorCount = $offsetAndSize & 0xFF;
        
        if ($sectorOffset === 0 || $sectorCount === 0) {
            return null;
        }
        
        $handle = fopen($regionFile, "rb");
        fseek($handle, $sectorOffset * 4096);
        $lengthData = fread($handle, 4);
        $length = unpack("N", $lengthData)[1];
        $compression = ord(fread($handle, 1));
        $data = fread($handle, $length - 1);
        fclose($handle);
        
        if ($compression === 2) { // zlib
            $data = gzuncompress($data);
        }
        
        return $data;
    }

    private function writeChunkToRegion(string $regionFile, int $chunkX, int $chunkZ, string $data): void {
        $localX = $chunkX & 31;
        $localZ = $chunkZ & 31;
        $index = ($localZ * 32 + $localX) * 4;
        
        $compressed = gzcompress($data);
        $length = strlen($compressed) + 1; // +1 for compression byte
        $sectorCount = (int)ceil($length / 4096);
        
        $handle = fopen($regionFile, "r+b");
        if (!$handle) return;
        
        // Find free space (simplified - just append)
        fseek($handle, 0, SEEK_END);
        $fileSize = ftell($handle);
        $sectorOffset = (int)ceil($fileSize / 4096);
        
        // Write chunk data
        fseek($handle, $sectorOffset * 4096);
        fwrite($handle, pack("N", $length));
        fwrite($handle, chr(2)); // zlib compression
        fwrite($handle, $compressed);
        
        // Pad to sector boundary
        $padding = $sectorCount * 4096 - $length - 5;
        if ($padding > 0) {
            fwrite($handle, str_repeat("\x00", $padding));
        }
        
        // Update index
        fseek($handle, $index);
        $offsetAndSize = ($sectorOffset << 8) | $sectorCount;
        fwrite($handle, pack("N", $offsetAndSize));
        
        // Update timestamp
        fseek($handle, 8192 + ($localZ * 32 + $localX) * 4);
        fwrite($handle, pack("N", time()));
        
        fclose($handle);
    }

    private function parseChunkData(string $data, int $chunkX, int $chunkZ): ChunkData {
        $stream = new \pocketmine\utils\BinaryStream($data);
        
        // Read chunk version
        $version = $stream->getByte();
        
        // Read sections
        $sections = [];
        $sectionCount = $stream->getByte();
        
        for ($i = 0; $i < $sectionCount; $i++) {
            $y = $stream->getByte();
            $blocks = $stream->get(4096); // 16x16x16 = 4096
            $data = $stream->get(4096);
            $skyLight = $stream->get(2048); // 4-bit per block
            $blockLight = $stream->get(2048);
            
            $sections[] = [
                'y' => $y,
                'blocks' => $blocks,
                'data' => $data,
                'skyLight' => $skyLight,
                'blockLight' => $blockLight,
            ];
        }
        
        // Biomes (256 bytes)
        $biomes = [];
        for ($i = 0; $i < 256; $i++) {
            $biomes[] = $stream->getByte();
        }
        
        // Heightmap (256 ints)
        $heightmap = [];
        for ($i = 0; $i < 256; $i++) {
            $heightmap[] = $stream->getInt();
        }
        
        // Entities
        $entityCount = $stream->getInt();
        $entities = [];
        for ($i = 0; $i < $entityCount; $i++) {
            $entities[] = $this->readEntity($stream);
        }
        
        // Tile entities
        $tileCount = $stream->getInt();
        $tileEntities = [];
        for ($i = 0; $i < $tileCount; $i++) {
            $tileEntities[] = $this->readTileEntity($stream);
        }
        
        return new ChunkData($chunkX, $chunkZ, $sections, $biomes, $heightmap, $entities, $tileEntities);
    }

    private function readEntity(\pocketmine\utils\BinaryStream $stream): EntitySnapshot {
        $entityId = $stream->getVarInt();
        $className = $stream->getString();
        $x = $stream->getDouble();
        $y = $stream->getDouble();
        $z = $stream->getDouble();
        $yaw = $stream->getFloat();
        $pitch = $stream->getFloat();
        
        // Components
        $componentCount = $stream->getInt();
        $components = [];
        for ($i = 0; $i < $componentCount; $i++) {
            $type = $stream->getString();
            $data = $stream->getString(); // Serialized component data
            $components[$type] = $data;
        }
        
        return new EntitySnapshot(
            (string)$entityId,
            $className,
            $x, $y, $z,
            $yaw, $pitch,
            $components
        );
    }

    private function readTileEntity(\pocketmine\utils\BinaryStream $stream): TileEntitySnapshot {
        $id = $stream->getVarInt();
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
        $stream = new \pocketmine\utils\BinaryStream();
        
        // Version
        $stream->putByte(1);
        
        // Sections
        $stream->putByte(count($data->sections));
        foreach ($data->sections as $section) {
            $stream->putByte($section['y']);
            $stream->put($section['blocks']);
            $stream->put($section['data'] ?? str_repeat("\x00", 4096));
            $stream->put($section['skyLight'] ?? str_repeat("\x00", 2048));
            $stream->put($section['blockLight'] ?? str_repeat("\x00", 2048));
        }
        
        // Biomes
        foreach ($data->biomes as $biome) {
            $stream->putByte($biome);
        }
        
        // Heightmap
        foreach ($data->heightmap as $height) {
            $stream->putInt($height);
        }
        
        // Entities
        $stream->putInt(count($data->entities));
        foreach ($data->entities as $entity) {
            $stream->putVarInt((int)$entity->id);
            $stream->putString($entity->type);
            $stream->putDouble($entity->x);
            $stream->putDouble($entity->y);
            $stream->putDouble($entity->z);
            $stream->putFloat($entity->yaw);
            $stream->putFloat($entity->pitch);
            
            $stream->putInt(count($entity->components));
            foreach ($entity->components as $type => $compData) {
                $stream->putString($type);
                $stream->putString($compData);
            }
        }
        
        // Tile entities
        $stream->putInt(count($data->tileEntities));
        foreach ($data->tileEntities as $tile) {
            $stream->putVarInt((int)$tile->id);
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

    private function saveLevel(Level $level): void {
        $worldFolder = $this->basePath . $level->getFolderName() . "/";
        $levelDat = $worldFolder . "level.dat";
        
        $nbt = new NBT(NBT::LITTLE_ENDIAN);
        $compound = new CompoundTag("");
        
        $compound->setTag(new StringTag("LevelName", $level->getFolderName()));
        $compound->setTag(new IntTag("Time", (int)$level->getTime()));
        $compound->setTag(new IntTag("SpawnX", (int)$level->getSpawn()->x));
        $compound->setTag(new IntTag("SpawnY", (int)$level->getSpawn()->y));
        $compound->setTag(new IntTag("SpawnZ", (int)$level->getSpawn()->z));
        $compound->setTag(new IntTag("Generator", 1)); // Default generator
        $compound->setTag(new LongTag("RandomSeed", $level->getSeed()));
        $compound->setTag(new IntTag("version", 1));
        
        // Entities
        $entityList = new ListTag("Entities");
        foreach ($level->getEntities() as $entity) {
            $entityCompound = new CompoundTag("");
            $entityCompound->setTag(new IntTag("Id", $entity->getId()));
            $entityCompound->setTag(new StringTag("Type", get_class($entity)));
            // Add more entity data
            $entityList->push($entityCompound);
        }
        $compound->setTag($entityList);
        
        $nbt->setData($compound);
        $nbt->writeCompressed($levelDat);
    }
}
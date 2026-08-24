<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\storage;

use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\ByteArrayTag;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\LongTag;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\core\resource\NativeAccel;
use pocketmine\port\driven\ChunkData;
use pocketmine\port\driven\EntitySnapshot;
use pocketmine\port\driven\StoragePort;
use pocketmine\port\driven\TileEntitySnapshot;
use pocketmine\utils\BinaryStream;
use function array_fill;
use function ceil;
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
use function gzdecode;
use function gzencode;
use function gzuncompress;
use function gzcompress;
use function is_dir;
use function mkdir;
use function ord;
use function pack;
use function preg_replace;
use function str_repeat;
use function time;
use function unpack;

/**
 * Shared region-file container (the .mca/.mcr on-disk layout).
 *
 * Both the Anvil and McRegion providers use the SAME region container: an
 * 8192-byte header (1024 4-byte chunk-location entries + 1024 4-byte
 * timestamps), then 4096-byte sectors holding length-prefixed,
 * compression-byte-tagged payloads. Only the chunk PAYLOAD differs between
 * formats (Anvil = Section list, McRegion = flat 128-height arrays), so the
 * container, player data files, and NBT entity/tile helpers live here once.
 */
abstract class RegionStorageAdapter implements StoragePort {
    private const SECTOR_SIZE = 4096;
    private const HEADER_SIZE = 8192;

    /** Compression-type byte written into each chunk record (zlib). */
    protected const COMPRESSION_ZLIB = 2;

    /** Magic prefix of the per-entity (player) data files. */
    private const PLAYER_MAGIC = 'KRONPLR1';

    protected string $basePath;
    protected string $levelName = "world";

    public function __construct(string $dataPath = "", string $levelName = "world") {
        $this->basePath = $dataPath !== "" ? $dataPath : "worlds/";
        $this->levelName = $levelName;

        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0755, true);
        }
    }

    /** The region-file extension for this format (".mca" or ".mcr"). */
    abstract protected function regionExtension(): string;

    /**
     * Serialize a chunk into the format-specific NBT payload (the exact
     * bytes that get compressed into a region record).
     */
    abstract protected function encodeChunkPayload(ChunkData $data): string;

    /**
     * Parse a format-specific NBT payload back into a ChunkData DTO, or null
     * when the payload is corrupt / a foreign format.
     */
    abstract protected function decodeChunkPayload(string $payload, int $chunkX, int $chunkZ): ?ChunkData;

    // --- Region container ---------------------------------------------------

    public function loadChunk(int $chunkX, int $chunkZ): ChunkData {
        $regionFile = $this->getRegionFile($chunkX, $chunkZ);

        if (!$regionFile || !file_exists($regionFile)) {
            return $this->generateEmptyChunk($chunkX, $chunkZ);
        }

        $raw = $this->readChunkFromRegion($regionFile, $chunkX, $chunkZ);

        if ($raw === null || $raw === "") {
            return $this->generateEmptyChunk($chunkX, $chunkZ);
        }

        $parsed = $this->decodeChunkPayload($raw, $chunkX, $chunkZ);
        return $parsed ?? $this->generateEmptyChunk($chunkX, $chunkZ);
    }

    public function saveChunk(int $chunkX, int $chunkZ, ChunkData $data): void {
        $regionFile = $this->getRegionFile($chunkX, $chunkZ);

        if (!$regionFile) {
            return;
        }

        $chunkBytes = $this->encodeChunkPayload($data);
        $this->writeChunkToRegion($regionFile, $chunkX, $chunkZ, $chunkBytes);
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

        $regionFile = $regionDir . "r.{$regionX}.{$regionZ}" . $this->regionExtension();

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
        $compression = ord((string)fread($handle, 1));
        $data = fread($handle, max(0, $length - 1));
        fclose($handle);

        if ($data === false) {
            return null;
        }

        // Real worlds may store chunks as gzip (1) or zlib (2). Both are
        // decompressed here so foreign Anvil/McRegion saves load correctly.
        if ($compression === 1) {
            $decompressed = gzdecode($data);
        } elseif ($compression === 2) {
            $decompressed = gzuncompress($data);
        } else {
            return null; // unknown / uncompressed / lz4: not a file we handle
        }
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
        fwrite($handle, chr(self::COMPRESSION_ZLIB));
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

    // --- World meta (level.dat) -------------------------------------------

    /** Magic + version header of the legacy pre-NBT world meta file. */
    private const WORLD_META_MAGIC = 0x4B524F4E; // 'KRON'
    private const WORLD_META_VERSION = 1;

    /**
     * Read an integer NBT field tolerantly: accepts Byte/Short/Int/Long tags
     * for the same field. Foreign (vanilla / old PocketMine) level.dat files
     * are inconsistent about integer width (e.g. Time as IntTag vs LongTag),
     * so a strict typed read would throw and discard the whole world meta.
     */
    private static function readIntValue(CompoundTag $data, string $name, int $default = 0): int {
        $tag = $data->getTag($name);
        if ($tag instanceof IntTag || $tag instanceof LongTag || $tag instanceof ByteTag || $tag instanceof ShortTag) {
            return (int)$tag->getValue();
        }
        return $default;
    }

    /**
     * Read the persisted world meta (seed/spawn/difficulty/time).
     *
     * level.dat is now a real gzip-compressed NBT "Data" compound (vanilla
     * layout, so real tools and other providers can read it). Worlds saved by
     * earlier Khronos builds used a custom binary format in the same file -
     * when the file is not NBT, that legacy format is parsed so old worlds
     * keep loading.
     *
     * @return array<string, string>|null null when no world has been saved
     */
    public function loadWorldMeta(): ?array {
        $file = $this->worldFolder() . 'level.dat';
        if (!file_exists($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }

        // gzip magic 0x1f 0x8b => real NBT level.dat; anything else is the
        // legacy KRON binary format.
        if (ord($raw[0]) === 0x1F && ord($raw[1]) === 0x8B) {
            try {
                $nbt = new NBT(NBT::BIG_ENDIAN);
                $nbt->readCompressed($raw);
                $root = $nbt->getData();
                $data = $root instanceof CompoundTag ? $root->getCompoundTag('Data') : null;
                if ($data === null) {
                    return null;
                }
                $meta = [];
                // Tolerant numeric reads: legacy PocketMine / vanilla level.dat
                // files are inconsistent about IntTag vs LongTag for the same
                // field (funil's 0.15-era file stores Time as IntTag). Old-src
                // used loose array access so any integer tag worked; matching
                // that keeps foreign worlds' spawn/seed intact instead of
                // failing the whole parse and treating the world as fresh.
                $meta['seed'] = (string)self::readIntValue($data, 'RandomSeed', 0);
                $meta['spawnX'] = (string)self::readIntValue($data, 'SpawnX', 0);
                $meta['spawnY'] = (string)self::readIntValue($data, 'SpawnY', 64);
                $meta['spawnZ'] = (string)self::readIntValue($data, 'SpawnZ', 0);
                $meta['time'] = (string)self::readIntValue($data, 'Time', 0);
                $meta['difficulty'] = (string)self::readIntValue($data, 'Difficulty', 1);
                if ($data->getTag('generatorName') !== null) {
                    $meta['generator'] = $data->getString('generatorName', 'normal');
                }
                // Detect imported (non-Khronos) worlds: has a generatorName
                // but no Khronos-specific markers. Imported flat worlds from
                // old PocketMine must not auto-generate terrain — remap to void.
                if ($data->getTag('KhronosWeather') !== null) {
                    $meta['weather'] = (string)self::readIntValue($data, 'KhronosWeather', 0);
                    $meta['khronos'] = '1';
                } else {
                    $meta['imported'] = '1';
                }
                if ($data->getTag('KhronosWeatherDuration') !== null) {
                    $meta['weatherDuration'] = (string)self::readIntValue($data, 'KhronosWeatherDuration', 0);
                }
                return $meta;
            } catch (\Throwable) {
                return null; // corrupt level.dat: treat as a fresh world
            }
        }

        // Legacy KRON binary world meta (pre-NBT Khronos saves).
        $stream = new BinaryStream($raw);
        if ($stream->getInt() !== self::WORLD_META_MAGIC) {
            return null;
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

    /**
     * Persist the world meta as a real NBT level.dat (vanilla "Data"
     * compound, gzip-compressed) so other providers and tools can read it.
     *
     * @param array<string, string> $meta string-keyed meta values
     */
    public function saveWorldMeta(array $meta): void {
        $dir = $this->worldFolder();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $data = new CompoundTag('Data', []);
        $data->setLong('RandomSeed', (int)($meta['seed'] ?? 0));
        $data->setInt('SpawnX', (int)($meta['spawnX'] ?? 0));
        $data->setInt('SpawnY', (int)($meta['spawnY'] ?? 64));
        $data->setInt('SpawnZ', (int)($meta['spawnZ'] ?? 0));
        $data->setLong('Time', (int)($meta['time'] ?? 0));
        $data->setByte('Difficulty', (int)($meta['difficulty'] ?? 1));
        $data->setString('generatorName', (string)($meta['generator'] ?? 'normal'));
        $data->setByte('KhronosWeather', (int)($meta['weather'] ?? 0));
        $data->setInt('KhronosWeatherDuration', (int)($meta['weatherDuration'] ?? 0));
        $data->setString('LevelName', $this->levelName);
        $data->setInt('version', 19133); // MCPE data version for 0.15-era worlds
        $data->setByte('hardcore', 0);
        $data->setByte('initialized', 1);
        $data->setString('KhronosFormat', 'nbt');
        $root = new CompoundTag('', []);
        $root->setTag('Data', $data);
        $nbt = new NBT(NBT::BIG_ENDIAN);
        $nbt->setData($root);
        file_put_contents($dir . 'level.dat', $nbt->writeCompressed());
    }

    // --- Nibble packing (vanilla 4-bit block-meta / light arrays) -----------

    /**
     * Pack a full-byte array (4096 bytes, one byte per block) into a vanilla
     * nibble array (2048 bytes, two blocks per byte: even index = low nibble).
     */
    protected static function packNibbles(string $fullBytes): string {
        $native = NativeAccel::packNibbles($fullBytes);
        if ($native !== null) {
            return $native;
        }
        $out = str_repeat("\x00", 2048);
        for ($i = 0; $i < 2048; $i++) {
            $low = ord($fullBytes[$i * 2]) & 0x0F;
            $high = ord($fullBytes[$i * 2 + 1]) & 0x0F;
            $out[$i] = chr(($high << 4) | $low);
        }
        return $out;
    }

    /**
     * Expand a vanilla nibble array (2048 bytes) into full bytes (4096).
     */
    protected static function unpackNibbles(string $nibbles): string {
        $native = NativeAccel::unpackNibbles($nibbles);
        if ($native !== null) {
            return $native;
        }
        $out = str_repeat("\x00", 4096);
        for ($i = 0; $i < 2048; $i++) {
            $byte = ord($nibbles[$i]);
            $out[$i * 2] = chr($byte & 0x0F);
            $out[$i * 2 + 1] = chr(($byte >> 4) & 0x0F);
        }
        return $out;
    }

    /**
     * Read one nibble (block meta / extended id) from a vanilla nibble array.
     */
    protected static function nibbleAt(string $nibbles, int $index): int {
        $byte = ord($nibbles[$index >> 1]);
        return ($index & 1) === 0 ? $byte & 0x0F : ($byte >> 4) & 0x0F;
    }

    // --- Player (entity) data files ----------------------------------------

    public function loadEntity(string $entityId): EntitySnapshot {
        $raw = @file_get_contents($this->entityFile($entityId));
        if ($raw === false || $raw === '') {
            return $this->emptyEntitySnapshot($entityId);
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data['magic'] ?? null) !== self::PLAYER_MAGIC) {
            return $this->emptyEntitySnapshot($entityId);
        }
        return new EntitySnapshot(
            $entityId,
            (string)($data['type'] ?? ''),
            (float)($data['x'] ?? 0.0),
            (float)($data['y'] ?? 0.0),
            (float)($data['z'] ?? 0.0),
            (float)($data['yaw'] ?? 0.0),
            (float)($data['pitch'] ?? 0.0),
            is_array($data['components'] ?? null) ? $data['components'] : [],
        );
    }

    public function saveEntity(EntitySnapshot $snapshot): void {
        $dir = $this->worldFolder() . 'players/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        try {
            $payload = json_encode([
                'magic' => self::PLAYER_MAGIC,
                'id' => $snapshot->id,
                'type' => $snapshot->type,
                'x' => $snapshot->x,
                'y' => $snapshot->y,
                'z' => $snapshot->z,
                'yaw' => $snapshot->yaw,
                'pitch' => $snapshot->pitch,
                'components' => $snapshot->components,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException) {
            return; // un-serializable component data: keep the last good save
        }
        // Atomic write: temp file + rename so a crash mid-write cannot
        // corrupt the last good save (same pattern as the region timestamps).
        $file = $this->entityFile($snapshot->id);
        file_put_contents($file . '.tmp', $payload);
        @rename($file . '.tmp', $file);
    }

    /** A per-entity data file, id-sanitized to prevent path traversal. */
    private function entityFile(string $entityId): string {
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $entityId);
        return $this->worldFolder() . 'players/' . ($safe !== null && $safe !== '' ? $safe : 'unknown') . '.dat';
    }

    private function emptyEntitySnapshot(string $entityId): EntitySnapshot {
        return new EntitySnapshot($entityId, '', 0.0, 0.0, 0.0, 0.0, 0.0, []);
    }

    protected function worldFolder(): string {
        return $this->basePath . $this->levelName . '/';
    }

    public function worldFolderExists(): bool {
        return is_dir($this->worldFolder());
    }

    // --- NBT helpers shared by the chunk payload codecs --------------------

    /**
     * Encode a chunk's entity list as a ListTag of vanilla-style compounds.
     * Each entity keeps its full internal snapshot (id, type, position,
     * rotation, component blobs) in a "KhronosData" byte array so round-trips
     * through this server are lossless; the vanilla fields (id/Pos/Motion/
     * Rotation) are written too so real tools see a valid entity record.
     *
     * @param EntitySnapshot[] $entities
     */
    protected function encodeEntities(array $entities): ListTag {
        $list = new ListTag("Entities", []);
        $list->setTagType(NBT::TAG_Compound);
        foreach ($entities as $entity) {
            $list[] = $this->encodeEntity($entity);
        }
        return $list;
    }

    protected function encodeEntity(EntitySnapshot $entity): CompoundTag {
        $compound = new CompoundTag("", []);
        $compound->setString("id", $entity->type !== '' ? $entity->type : ($entity->id !== '' ? $entity->id : 'Entity'));
        $compound->setString("KhronosId", $entity->id);
        $compound->setTag("Pos", $this->doubleList([$entity->x, $entity->y, $entity->z]));
        $compound->setTag("Motion", $this->doubleList([0.0, 0.0, 0.0]));
        $compound->setTag("Rotation", $this->floatList([$entity->yaw, $entity->pitch]));
        try {
            $compound->setByteArray("KhronosData", json_encode([
                'id' => $entity->id,
                'type' => $entity->type,
                'yaw' => $entity->yaw,
                'pitch' => $entity->pitch,
                'components' => $entity->components,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } catch (\JsonException) {
            // un-serializable components: still keep the vanilla position
        }
        return $compound;
    }

    /**
     * Decode a chunk's entity ListTag back into EntitySnapshot[].
     * Prefers the KhronosData blob (exact internal round-trip); foreign
     * entities without one are mapped from the vanilla fields.
     *
     * @return EntitySnapshot[]
     */
    protected function decodeEntities(?ListTag $list): array {
        if ($list === null) {
            return [];
        }
        $out = [];
        foreach ($list as $tag) {
            if (!$tag instanceof CompoundTag) {
                continue;
            }
            $snapshot = $this->decodeEntity($tag);
            if ($snapshot !== null) {
                $out[] = $snapshot;
            }
        }
        return $out;
    }

    protected function decodeEntity(CompoundTag $tag): ?EntitySnapshot {
        $id = $tag->getString('KhronosId', '');
        $type = $tag->getString('id', '');
        $pos = $tag->getListTag('Pos');
        $rot = $tag->getListTag('Rotation');

        $raw = $tag->getByteArray('KhronosData', '');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return new EntitySnapshot(
                    $id !== '' ? $id : (string)($decoded['id'] ?? ''),
                    $type !== '' ? $type : (string)($decoded['type'] ?? ''),
                    (float)($decoded['x'] ?? $this->listValue($pos, 0, 0.0)),
                    (float)($decoded['y'] ?? $this->listValue($pos, 1, 0.0)),
                    (float)($decoded['z'] ?? $this->listValue($pos, 2, 0.0)),
                    (float)($decoded['yaw'] ?? $this->listValue($rot, 0, 0.0)),
                    (float)($decoded['pitch'] ?? $this->listValue($rot, 1, 0.0)),
                    is_array($decoded['components'] ?? null) ? $decoded['components'] : [],
                );
            }
        }

        // Foreign entity (no KhronosData): map the vanilla fields.
        return new EntitySnapshot(
            $id !== '' ? $id : $type,
            $type,
            $this->listValue($pos, 0, 0.0),
            $this->listValue($pos, 1, 0.0),
            $this->listValue($pos, 2, 0.0),
            $this->listValue($rot, 0, 0.0),
            $this->listValue($rot, 1, 0.0),
            [],
        );
    }

    /**
     * Encode a chunk's tile-entity list. Same strategy as entities: the
     * full internal snapshot rides a "KhronosData" blob (lossless for this
     * server); id + x/y/z are written vanilla-style so real readers see a
     * valid tile record.
     *
     * @param TileEntitySnapshot[] $tiles
     */
    protected function encodeTileEntities(array $tiles): ListTag {
        $list = new ListTag("TileEntities", []);
        $list->setTagType(NBT::TAG_Compound);
        foreach ($tiles as $tile) {
            $compound = new CompoundTag("", []);
            $compound->setString("id", $tile->type);
            $compound->setString("KhronosId", $tile->id);
            $compound->setInt("x", $tile->x);
            $compound->setInt("y", $tile->y);
            $compound->setInt("z", $tile->z);
            try {
                $compound->setByteArray("KhronosData", json_encode([
                    'id' => $tile->id,
                    'type' => $tile->type,
                    'data' => $tile->data,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } catch (\JsonException) {
                // un-serializable tile data: keep id/x/y/z
            }
            $list[] = $compound;
        }
        return $list;
    }

    /**
     * @return TileEntitySnapshot[]
     */
    protected function decodeTileEntities(?ListTag $list): array {
        if ($list === null) {
            return [];
        }
        $out = [];
        foreach ($list as $tag) {
            if (!$tag instanceof CompoundTag) {
                continue;
            }
            $raw = $tag->getByteArray('KhronosData', '');
            $decoded = $raw !== '' ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $out[] = new TileEntitySnapshot(
                    (string)($decoded['id'] ?? $tag->getString('KhronosId', '')),
                    (string)($decoded['type'] ?? $tag->getString('id', '')),
                    (int)($decoded['x'] ?? $tag->getInt('x', 0)),
                    (int)($decoded['y'] ?? $tag->getInt('y', 0)),
                    (int)($decoded['z'] ?? $tag->getInt('z', 0)),
                    is_array($decoded['data'] ?? null) ? $decoded['data'] : [],
                );
            } else {
                // Foreign tile entity: preserve the fields we can read.
                $out[] = new TileEntitySnapshot(
                    $tag->getString('KhronosId', ''),
                    $tag->getString('id', ''),
                    $tag->getInt('x', 0),
                    $tag->getInt('y', 0),
                    $tag->getInt('z', 0),
                    [],
                );
            }
        }
        return $out;
    }

    // --- Small NBT builders ------------------------------------------------

    /** @param list<float> $values */
    protected function doubleList(array $values): ListTag {
        $list = new ListTag("", []);
        $list->setTagType(NBT::TAG_Double);
        foreach ($values as $v) {
            $list[] = new DoubleTag("", (float)$v);
        }
        return $list;
    }

    /** @param list<float> $values */
    protected function floatList(array $values): ListTag {
        $list = new ListTag("", []);
        $list->setTagType(NBT::TAG_Float);
        foreach ($values as $v) {
            $list[] = new FloatTag("", (float)$v);
        }
        return $list;
    }

    private function listValue(?ListTag $list, int $index, float $default): float {
        if ($list === null) {
            return $default;
        }
        $i = 0;
        foreach ($list as $tag) {
            if ($i === $index) {
                return (float)$tag->getValue();
            }
            $i++;
        }
        return $default;
    }

    protected function generateEmptyChunk(int $chunkX, int $chunkZ): ChunkData {
        return new ChunkData($chunkX, $chunkZ, [], array_fill(0, 256, 0), array_fill(0, 256, 0), [], []);
    }
}

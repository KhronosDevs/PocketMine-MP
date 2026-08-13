<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\storage;

use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\ByteArrayTag;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\LongTag;
use pocketmine\port\driven\ChunkData;
use pocketmine\port\driven\EntitySnapshot;
use pocketmine\port\driven\TileEntitySnapshot;
use pocketmine\utils\BinaryStream;
use function array_fill;
use function count;
use function ord;
use function str_repeat;
use function strlen;

/**
 * Real vanilla Anvil (MCPE/PocketMine .mca) chunk storage.
 *
 * Each region record is a zlib-compressed, big-endian NBT chunk: a root
 * CompoundTag whose "Level" child holds xPos/zPos, Biomes, HeightMap (int
 * array), a list of per-16-block Sections (Blocks/Data/SkyLight/BlockLight
 * nibble arrays), plus the entity and tile-entity lists. This is the exact
 * on-disk layout of real Anvil worlds, so a world saved by MCPE or by
 * PocketMine-MP's Anvil provider loads here, and worlds saved here open in
 * standard tools.
 *
 * Chunks written by EARLIER Khronos builds used a custom binary payload in
 * the same .mca container (version byte 1). Those are detected (the first
 * byte is not the NBT compound tag 0x0A) and parsed by the legacy reader,
 * so existing worlds keep loading and are transparently converted to real
 * Anvil on the next save.
 */
final class AnvilStorageAdapter extends RegionStorageAdapter {
    protected function regionExtension(): string {
        return '.mca';
    }

    protected function encodeChunkPayload(ChunkData $data): string {
        $level = new CompoundTag('Level', []);
        $level->setInt('xPos', $data->chunkX);
        $level->setInt('zPos', $data->chunkZ);
        $level->setLong('LastUpdate', 0);
        $level->setByte('LightPopulated', 1);
        $level->setByte('TerrainPopulated', 1);
        $level->setByte('V', 1);
        $level->setLong('InhabitedTime', 0);

        $biomes = str_repeat("\x00", 256);
        foreach ($data->biomes as $i => $biome) {
            if ($i < 256) {
                $biomes[$i] = chr($biome & 0xFF);
            }
        }
        $level->setByteArray('Biomes', $biomes);

        // Vanilla Anvil stores the heightmap as an int array (256 entries).
        $heightmap = $data->heightmap;
        if (count($heightmap) !== 256) {
            $heightmap = array_fill(0, 256, 0);
        }
        $level->setIntArray('HeightMap', $heightmap);

        $sections = new ListTag('Sections', []);
        $sections->setTagType(NBT::TAG_Compound);
        foreach ($data->sections as $section) {
            $sy = (int)$section['y'];
            if ($sy < 0 || $sy > 15) {
                continue;
            }
            $compound = new CompoundTag('', []);
            $compound->setByte('Y', $sy);
            $blocks = (string)($section['blocks'] ?? '');
            if (strlen($blocks) !== 4096) {
                $blocks = str_repeat("\x00", 4096);
            }
            $compound->setByteArray('Blocks', $blocks);
            $compound->setByteArray('Data', self::packNibbles((string)($section['data'] ?? str_repeat("\x00", 4096))));
            $compound->setByteArray('SkyLight', (string)($section['skyLight'] ?? str_repeat("\xff", 2048)));
            $compound->setByteArray('BlockLight', (string)($section['blockLight'] ?? str_repeat("\x00", 2048)));
            $sections[] = $compound;
        }
        $level->setTag('Sections', $sections);
        $level->setTag('Entities', $this->encodeEntities($data->entities));
        $level->setTag('TileEntities', $this->encodeTileEntities($data->tileEntities));

        $root = new CompoundTag('', []);
        $root->setTag('Level', $level);
        $nbt = new NBT(NBT::BIG_ENDIAN);
        $nbt->setData($root);
        return $nbt->write();
    }

    protected function decodeChunkPayload(string $payload, int $chunkX, int $chunkZ): ?ChunkData {
        // Real Anvil chunks start with the NBT compound tag (0x0A). Chunks
        // from pre-NBT Khronos builds start with their version byte (0x01).
        if (ord($payload[0]) === NBT::TAG_Compound) {
            return $this->parseNbtChunk($payload, $chunkX, $chunkZ);
        }
        return $this->parseLegacyChunk($payload, $chunkX, $chunkZ);
    }

    private function parseNbtChunk(string $payload, int $chunkX, int $chunkZ): ?ChunkData {
        try {
            $nbt = new NBT(NBT::BIG_ENDIAN);
            $nbt->read($payload);
            $root = $nbt->getData();
            if (!$root instanceof CompoundTag) {
                return null;
            }
            $level = $root->getCompoundTag('Level');
            if ($level === null) {
                return null;
            }

            $sections = [];
            $sectionsTag = $level->getListTag('Sections');
            if ($sectionsTag !== null) {
                foreach ($sectionsTag as $tag) {
                    if (!$tag instanceof CompoundTag) {
                        continue;
                    }
                    $sy = $tag->getByte('Y', 0);
                    if ($sy < 0 || $sy > 15) {
                        continue;
                    }
                    $blocks = $tag->getByteArray('Blocks', '');
                    if (strlen($blocks) !== 4096) {
                        $blocks = str_repeat("\x00", 4096);
                    }
                    // Apply the extended-id nibble array (Add) so chunks with
                    // block ids above 255 still render (clamped to the 8-bit
                    // internal store, which 0.15 content never exceeds).
                    $add = $tag->getByteArray('Add', '');
                    if (strlen($add) === 2048) {
                        $blocks = $this->applyAddArray($blocks, $add);
                    }
                    $sections[] = [
                        'y' => $sy,
                        'blocks' => $blocks,
                        'data' => self::unpackNibbles($tag->getByteArray('Data', str_repeat("\x00", 2048))),
                        'skyLight' => $tag->getByteArray('SkyLight', str_repeat("\xff", 2048)),
                        'blockLight' => $tag->getByteArray('BlockLight', str_repeat("\x00", 2048)),
                    ];
                }
            }
            usort($sections, static fn(array $a, array $b): int => $a['y'] <=> $b['y']);

            $biomes = [];
            $biomesRaw = $level->getByteArray('Biomes', '');
            for ($i = 0; $i < 256; $i++) {
                $biomes[] = strlen($biomesRaw) > $i ? ord($biomesRaw[$i]) : 0;
            }

            $heightmap = $level->getIntArray('HeightMap', array_fill(0, 256, 0));
            if (count($heightmap) !== 256) {
                $heightmap = array_fill(0, 256, 0);
            }

            return new ChunkData(
                $chunkX,
                $chunkZ,
                $sections,
                $biomes,
                $heightmap,
                $this->decodeEntities($level->getListTag('Entities')),
                $this->decodeTileEntities($level->getListTag('TileEntities')),
            );
        } catch (\Throwable) {
            return null; // corrupt / foreign payload: treat as an empty chunk
        }
    }

    /** Fold the 4-bit Add array into the block ids (clamped to 0-255). */
    private function applyAddArray(string $blocks, string $add): string {
        $out = $blocks;
        for ($i = 0; $i < 4096; $i++) {
            $id = ord($blocks[$i]) + (self::nibbleAt($add, $i) << 8);
            $out[$i] = chr(min(255, $id));
        }
        return $out;
    }

    /**
     * Legacy pre-NBT Khronos chunk payload (the format this adapter wrote
     * before the real-Anvil rewrite): version byte, section count, then per
     * section [y][blocks 4096][data 4096][skyLight 2048][blockLight 2048],
     * biomes, heightmap (ints), entities, tile entities.
     */
    private function parseLegacyChunk(string $payload, int $chunkX, int $chunkZ): ?ChunkData {
        try {
            $stream = new BinaryStream($payload);
            if ($stream->getByte() !== 1) {
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
                $entities[] = $this->readLegacyEntity($stream);
            }
            $tileEntities = [];
            $tileCount = $stream->getInt();
            for ($i = 0; $i < $tileCount; $i++) {
                $tileEntities[] = $this->readLegacyTileEntity($stream);
            }
            return new ChunkData($chunkX, $chunkZ, $sections, $biomes, $heightmap, $entities, $tileEntities);
        } catch (\Throwable) {
            return null;
        }
    }

    private function readLegacyEntity(BinaryStream $stream): EntitySnapshot {
        $entityId = (string)$stream->getString();
        $className = (string)$stream->getString();
        $x = $stream->getDouble();
        $y = $stream->getDouble();
        $z = $stream->getDouble();
        $yaw = $stream->getFloat();
        $pitch = $stream->getFloat();
        $components = [];
        $componentCount = $stream->getInt();
        for ($i = 0; $i < $componentCount; $i++) {
            $type = (string)$stream->getString();
            $componentData = (string)$stream->getString();
            $components[$type] = $componentData;
        }
        return new EntitySnapshot($entityId, $className, $x, $y, $z, $yaw, $pitch, $components);
    }

    private function readLegacyTileEntity(BinaryStream $stream): TileEntitySnapshot {
        $id = (string)$stream->getString();
        $className = (string)$stream->getString();
        $x = $stream->getInt();
        $y = $stream->getInt();
        $z = $stream->getInt();
        $dataLength = $stream->getInt();
        $data = $stream->get($dataLength);
        return new TileEntitySnapshot($id, $className, $x, $y, $z, ['nbt' => base64_encode($data)]);
    }
}

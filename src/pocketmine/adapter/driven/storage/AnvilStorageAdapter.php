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
use function intdiv;
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
            // Java 1.13-1.17 wraps everything in "Level"; 1.18+ moved the
            // tags to the chunk root (sections/entities lowercased).
            $level = $root->getCompoundTag('Level') ?? $root;

            $sections = [];
            $sectionsTag = $level->getListTag('Sections') ?? $level->getListTag('sections');
            if ($sectionsTag !== null) {
                foreach ($sectionsTag as $tag) {
                    if (!$tag instanceof CompoundTag) {
                        continue;
                    }
                    $sy = $tag->getByte('Y', 0);
                    if ($sy < 0 || $sy > 15) {
                        continue;
                    }

                    // 1.13+ sections carry a block-state palette instead of
                    // raw Blocks/Data arrays: "Palette" directly on the
                    // section (1.13-1.17) or a "block_states" container with
                    // lowercase keys (1.18+).
                    $palette = $tag->getCompoundTag('block_states')
                        ?? ($tag->getTag('Palette') !== null ? $tag : null);
                    if ($palette !== null) {
                        $decoded = $this->decodePaletteSection($palette, $root, $sy, $tag);
                        if ($decoded !== null) {
                            $sections[] = $decoded;
                        }
                        continue;
                    }

                    $blocks = $tag->getByteArray('Blocks', '');
                    if (strlen($blocks) !== 4096) {
                        $blocks = str_repeat("\x00", 4096);
                    }
                    // Apply the extended-id nibble array (Add) so chunks with
                    // block ids above 255 still load (clamped to the 8-bit
                    // internal store, which 0.15 content never exceeds).
                    $add = $tag->getByteArray('Add', '');
                    if (strlen($add) === 2048) {
                        $blocks = $this->applyAddArray($blocks, $add);
                    }
                    $data = self::unpackNibbles($tag->getByteArray('Data', str_repeat("\x00", 2048)));
                    // Sanitize Java-world states (ids PE 0.15 cannot render
                    // and meta bits it does not model) into renderable ones.
                    [$blocks, $data] = JavaBlockTranslator::sanitizeSection($blocks, $data);
                    $sections[] = [
                        'y' => $sy,
                        'blocks' => $blocks,
                        'data' => $data,
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

            $heightmap = $level->getIntArray('HeightMap', []);
            if (count($heightmap) !== 256) {
                // Modern Java chunks omit the int heightmap (or it lives in
                // the packed Heightmaps/MOTION_BLOCKING buffer): derive it
                // from the blocks so sky-light and spawning behave.
                $heightmap = $this->computeHeightmap($sections);
            }

            $entities = $level->getListTag('Entities') ?? $level->getListTag('entities');
            $tiles = $level->getListTag('TileEntities') ?? $level->getListTag('block_entities');

            return new ChunkData(
                $chunkX,
                $chunkZ,
                $sections,
                $biomes,
                $heightmap,
                $this->decodeEntities($entities),
                $this->decodeTileEntities($tiles),
            );
        } catch (\Throwable) {
            return null; // corrupt / foreign payload: treat as an empty chunk
        }
    }

    /**
     * Decode one palette section (1.9+) into the internal
     * [y, blocks, data, lights] shape, or null when it is unreadable.
     *
     * Two palette flavours exist:
     *  - 1.9-1.12: palette entries are IntTags holding legacy numeric
     *    states ((id << 4) | meta) - decoded then run through the numeric
     *    sanitizer like any pre-1.13 chunk.
     *  - 1.13+: palette entries are named compounds ("minecraft:stone" +
     *    properties) - resolved through the JavaBlockTranslator name table.
     *
     * @return array<string, mixed>|null
     */
    private function decodePaletteSection(CompoundTag $palette, CompoundTag $chunkRoot, int $sy, CompoundTag $section): ?array {
        $entries = $palette->getListTag('Palette') ?? $palette->getListTag('palette');
        if ($entries === null) {
            return null;
        }
        $longs = $palette->getLongArray('BlockStates', []);
        if ($longs === []) {
            $longs = $palette->getLongArray('data', []);
        }

        $namedStates = []; // [name, props] for 1.13+ palettes
        $numericStates = []; // state ids for 1.9-1.12 palettes
        $isNamed = null;
        foreach ($entries as $entry) {
            if ($entry instanceof CompoundTag) {
                $nameTag = $entry->getTag('Name') ?? $entry->getTag('name');
                $name = $nameTag instanceof \pocketmine\nbt\tag\StringTag ? $nameTag->getValue() : '';
                $props = [];
                $propsTag = $entry->getCompoundTag('Properties') ?? $entry->getCompoundTag('properties');
                if ($propsTag !== null) {
                    foreach ($propsTag as $prop) {
                        if ($prop instanceof \pocketmine\nbt\tag\NamedTag) {
                            $props[$prop->getName()] = (string)$prop->getValue();
                        }
                    }
                }
                $namedStates[] = [$name, $props];
                $isNamed = true;
            } elseif ($entry instanceof \pocketmine\nbt\tag\IntTag) {
                $numericStates[] = $entry->getValue();
                $isNamed = $isNamed ?? false;
            }
        }
        if ($isNamed === null) {
            return null;
        }

        // 1.16 changed the index packing so values never straddle a 64-bit
        // word boundary; earlier versions pack one continuous bit stream.
        $dataVersion = 0;
        $dv = $chunkRoot->getTag('DataVersion');
        if ($dv instanceof \pocketmine\nbt\tag\IntTag || $dv instanceof \pocketmine\nbt\tag\LongTag) {
            $dataVersion = (int)$dv->getValue();
        }
        $continuous = $dataVersion > 0 && $dataVersion < 2529;

        if ($isNamed) {
            [$blocks, $data] = JavaBlockTranslator::decodePaletteSection($namedStates, $longs, $continuous);
        } else {
            [$blocks, $data] = $this->decodeNumericPalette($numericStates, $longs, $continuous);
        }

        return [
            'y' => $sy,
            'blocks' => $blocks,
            'data' => $data,
            'skyLight' => $section->getByteArray('SkyLight', str_repeat("\xff", 2048)),
            'blockLight' => $section->getByteArray('BlockLight', str_repeat("\x00", 2048)),
        ];
    }

    /**
     * Decode a 1.9-1.12 numeric palette: each slot is a legacy
     * (id << 4) | meta state. Sanitized like any numeric chunk.
     *
     * @param int[] $states
     * @param int[] $longs
     * @return array{0: string, 1: string} [blocks, data]
     */
    private function decodeNumericPalette(array $states, array $longs, bool $continuous): array {
        $count = count($states);
        if ($count === 0) {
            $air = str_repeat("\x00", 4096);
            return [$air, $air];
        }
        $bits = 1;
        while ((1 << $bits) < $count) {
            $bits++;
        }
        if ($bits < 4) {
            $bits = 4;
        }
        $blocks = str_repeat("\x00", 4096);
        $data = str_repeat("\x00", 4096);
        if ($count === 1) {
            $state = $states[0];
            $idByte = chr(($state >> 4) & 0xFF);
            $metaByte = chr($state & 0x0F);
            for ($i = 0; $i < 4096; $i++) {
                $blocks[$i] = $idByte;
                $data[$i] = $metaByte;
            }
        } elseif ($continuous) {
            // 1.9-1.15: one uninterrupted little-endian bit stream.
            $totalBits = count($longs) * 64;
            for ($k = 0; $k < 4096 && $k * $bits + $bits <= $totalBits; $k++) {
                $state = JavaBlockTranslator::peekBits($longs, $k * $bits, $bits);
                $state = $states[$state] ?? $states[0];
                $blocks[$k] = chr(($state >> 4) & 0xFF);
                $data[$k] = chr($state & 0x0F);
            }
        } else {
            // 1.16+ layout: floor(64 / bits) values per word from bit 0.
            $perWord = intdiv(64, $bits);
            $totalWords = count($longs);
            for ($k = 0; $k < 4096; $k++) {
                $word = intdiv($k, $perWord);
                if ($word >= $totalWords) {
                    break;
                }
                $off = ($k % $perWord) * $bits;
                $state = JavaBlockTranslator::peekBits($longs, $word * 64 + $off, $bits);
                $state = $states[$state] ?? $states[0];
                $blocks[$k] = chr(($state >> 4) & 0xFF);
                $data[$k] = chr($state & 0x0F);
            }
        }
        return JavaBlockTranslator::sanitizeSection($blocks, $data);
    }

    /**
     * Heightmap fallback for chunks saved without one (modern Java): the
     * highest non-air block per column + 1, capped at the wire height.
     *
     * @param array<int, array<string, mixed>> $sections
     * @return int[]
     */
    private function computeHeightmap(array $sections): array {
        $heightmap = array_fill(0, 256, 0);
        foreach ($sections as $section) {
            $sy = (int)$section['y'];
            if ($sy < 0 || $sy > 15) {
                continue;
            }
            $blocks = (string)$section['blocks'];
            $base = $sy * 16;
            for ($y = 0; $y < 16; $y++) {
                $worldY = $base + $y;
                if ($worldY >= 128) {
                    break; // above the 0.15 world height: never recorded
                }
                $rowOffset = $y * 256;
                for ($i = 0; $i < 256; $i++) {
                    $id = ord($blocks[$rowOffset + $i]);
                    if ($id !== 0 && $worldY + 1 > $heightmap[$i]) {
                        $heightmap[$i] = $worldY + 1;
                    }
                }
            }
        }
        return $heightmap;
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

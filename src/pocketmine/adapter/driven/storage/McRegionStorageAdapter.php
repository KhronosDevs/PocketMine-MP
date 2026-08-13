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
use function array_fill;
use function chr;
use function count;
use function ltrim;
use function ord;
use function str_repeat;
use function strlen;
use function substr;
use function substr_replace;

/**
 * McRegion (.mcr) chunk storage - the classic 128-height format used by
 * Minecraft Alpha/Beta and legacy PocketMine worlds.
 *
 * The region container is identical to Anvil (same header, same zlib
 * records); only the chunk payload differs: instead of a per-16-block
 * section list, the whole 128-block column is stored as flat arrays -
 * Blocks (32768 bytes, Y-major), Data / SkyLight / BlockLight (16384
 * nibble-packed bytes each), a byte HeightMap (not the int array Anvil
 * uses), plus Biomes, Entities and TileEntities. All big-endian NBT.
 *
 * Blocks above Y=127 do not exist in this format and are dropped on save
 * (a McRegion world is 128 blocks tall by definition).
 */
final class McRegionStorageAdapter extends RegionStorageAdapter {
    private const HEIGHT = 128;         // blocks per column
    private const SECTION_COUNT = 8;    // 128 / 16
    private const FLAT_BLOCKS = 32768;  // 16 * 16 * 128
    private const FLAT_NIBBLES = 16384; // FLAT_BLOCKS / 2

    protected function regionExtension(): string {
        return '.mcr';
    }

    protected function encodeChunkPayload(ChunkData $data): string {
        $blocks = str_repeat("\x00", self::FLAT_BLOCKS);
        $metaFull = str_repeat("\x00", self::FLAT_BLOCKS);
        $skyLight = str_repeat("\xff", self::FLAT_NIBBLES);
        $blockLight = str_repeat("\x00", self::FLAT_NIBBLES);

        foreach ($data->sections as $section) {
            $sy = (int)$section['y'];
            if ($sy < 0 || $sy >= self::SECTION_COUNT) {
                continue; // McRegion cannot store blocks above Y=127
            }
            $offset = $sy * 4096;
            $sectionBlocks = (string)($section['blocks'] ?? '');
            if (strlen($sectionBlocks) === 4096) {
                $blocks = substr_replace($blocks, $sectionBlocks, $offset, 4096);
            }
            $sectionMeta = (string)($section['data'] ?? str_repeat("\x00", 4096));
            if (strlen($sectionMeta) === 4096) {
                $metaFull = substr_replace($metaFull, $sectionMeta, $offset, 4096);
            }
            $skyLight = substr_replace($skyLight, (string)($section['skyLight'] ?? str_repeat("\xff", 2048)), $sy * 2048, 2048);
            $blockLight = substr_replace($blockLight, (string)($section['blockLight'] ?? str_repeat("\x00", 2048)), $sy * 2048, 2048);
        }

        $metaNibbles = '';
        for ($sy = 0; $sy < self::SECTION_COUNT; $sy++) {
            $metaNibbles .= self::packNibbles(substr($metaFull, $sy * 4096, 4096));
        }

        $level = new CompoundTag('Level', []);
        $level->setInt('xPos', $data->chunkX);
        $level->setInt('zPos', $data->chunkZ);
        $level->setLong('LastUpdate', 0);
        $level->setByte('LightPopulated', 1);
        $level->setByte('TerrainPopulated', 1);
        $level->setByte('V', 1);
        $level->setLong('InhabitedTime', 0);
        $level->setByteArray('Blocks', $blocks);
        $level->setByteArray('Data', $metaNibbles);
        $level->setByteArray('SkyLight', $skyLight);
        $level->setByteArray('BlockLight', $blockLight);

        // McRegion's heightmap is a BYTE array (256 entries, 0-127), not the
        // int array Anvil uses.
        $heightBytes = str_repeat("\x00", 256);
        foreach ($data->heightmap as $i => $height) {
            if ($i < 256) {
                $heightBytes[$i] = chr(min(self::HEIGHT - 1, max(0, (int)$height)));
            }
        }
        $level->setByteArray('HeightMap', $heightBytes);

        $biomes = str_repeat("\x00", 256);
        foreach ($data->biomes as $i => $biome) {
            if ($i < 256) {
                $biomes[$i] = chr($biome & 0xFF);
            }
        }
        $level->setByteArray('Biomes', $biomes);

        $level->setTag('Entities', $this->encodeEntities($data->entities));
        $level->setTag('TileEntities', $this->encodeTileEntities($data->tileEntities));

        $root = new CompoundTag('', []);
        $root->setTag('Level', $level);
        $nbt = new NBT(NBT::BIG_ENDIAN);
        $nbt->setData($root);
        return $nbt->write();
    }

    protected function decodeChunkPayload(string $payload, int $chunkX, int $chunkZ): ?ChunkData {
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

            $blocks = $level->getByteArray('Blocks', '');
            $metaNibbles = $level->getByteArray('Data', '');
            $skyLight = $level->getByteArray('SkyLight', '');
            $blockLight = $level->getByteArray('BlockLight', '');
            if (strlen($blocks) < self::FLAT_BLOCKS
                || strlen($metaNibbles) < self::FLAT_NIBBLES
                || strlen($skyLight) < self::FLAT_NIBBLES
                || strlen($blockLight) < self::FLAT_NIBBLES) {
                return null; // not a 128-height McRegion column
            }

            $sections = [];
            for ($sy = 0; $sy < self::SECTION_COUNT; $sy++) {
                $sectionBlocks = substr($blocks, $sy * 4096, 4096);
                if (ltrim($sectionBlocks, "\x00") === '') {
                    continue; // empty section: skip (matches Anvil behavior)
                }
                $sections[] = [
                    'y' => $sy,
                    'blocks' => $sectionBlocks,
                    'data' => self::unpackNibbles(substr($metaNibbles, $sy * 2048, 2048)),
                    'skyLight' => substr($skyLight, $sy * 2048, 2048),
                    'blockLight' => substr($blockLight, $sy * 2048, 2048),
                ];
            }

            $heightmap = [];
            $heightBytes = $level->getByteArray('HeightMap', '');
            for ($i = 0; $i < 256; $i++) {
                $heightmap[] = strlen($heightBytes) > $i ? ord($heightBytes[$i]) : 0;
            }

            $biomes = [];
            $biomesRaw = $level->getByteArray('Biomes', '');
            for ($i = 0; $i < 256; $i++) {
                $biomes[] = strlen($biomesRaw) > $i ? ord($biomesRaw[$i]) : 0;
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
}

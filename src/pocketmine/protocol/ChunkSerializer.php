<?php

declare(strict_types=1);

namespace pocketmine\protocol;

use pocketmine\port\driven\ChunkData;
use function chr;
use function ord;
use function pack;
use function str_repeat;
use function strlen;
use function substr;

/**
 * Serializes core ChunkData into the protocol-84 FullChunkDataPacket payload.
 *
 * Wire format (ORDER_LAYERED, matching the old Anvil provider):
 *   - block ids:   8 sections x 4096 bytes (missing sections are air)
 *   - block data:  8 sections x 2048 bytes, nibble-packed (even index = low nibble)
 *   - sky light:   8 sections x 2048 bytes
 *   - block light: 8 sections x 2048 bytes
 *   - height map:  256 bytes (one byte per column)
 *   - biome color: 256 x 4 bytes (packed RGBA ints)
 *   - extra data:  little-endian int count (always 0 here)
 *   - tiles:       empty (no NBT tile entities in the core model yet)
 *
 * Protocol 84 worlds are 128 blocks high, so exactly 8 sections are sent.
 * The core generator produces at most 16 sections (y 0..255); anything above
 * y=127 is dropped on the wire.
 */
final class ChunkSerializer {
    private const SECTIONS = 8;

    /** @var array<int, int> biome id => RGBA color (cosmetic; plains default) */
    private const BIOME_COLORS = [
        1 => 0x7FB238, // plains
        2 => 0xBFB755, // desert
        3 => 0x879A63, // extreme hills
        4 => 0x8DB360, // forest
    ];

    public static function serialize(ChunkData $data): string {
        // Collect sections by y, dropping anything >= 128 (out of protocol-84
        // height range) and filling gaps with air.
        /** @var array<int, array<string, string>> $byY */
        $byY = [];
        foreach ($data->sections as $section) {
            $y = (int)($section['y'] ?? 0);
            if ($y < 0 || $y >= self::SECTIONS) {
                continue;
            }
            $byY[$y] = $section;
        }

        $airBlocks = str_repeat("\x00", 4096);
        $airLight = str_repeat("\x00", 2048);

        $blocks = '';
        $blockData = '';
        $blockLight = '';
        for ($y = 0; $y < self::SECTIONS; $y++) {
            $section = $byY[$y] ?? null;
            $blocks .= $section !== null ? (string)$section['blocks'] : $airBlocks;
            $blockData .= self::packNibbles($section['data'] ?? $airBlocks);
            $blockLight .= $section !== null ? (string)($section['blockLight'] ?? $airLight) : $airLight;
        }
        // Sky light is derived from the height map so exposed blocks render
        // fully lit and underground blocks stay dark (the generator does not
        // emit lighting data, and sending zeros would make the world black).
        $skyLight = self::buildSkyLight($data->heightmap);

        // Height map: clamp to byte range (0..127 for protocol-84 worlds).
        $heightMap = '';
        foreach ($data->heightmap as $height) {
            $heightMap .= chr(max(0, min(127, (int)$height)));
        }

        // Biome colors from the biome id table (cosmetic terrain tint).
        $biomes = '';
        foreach ($data->biomes as $biome) {
            $biomes .= pack('N', self::BIOME_COLORS[(int)$biome] ?? self::BIOME_COLORS[1]);
        }

        // Extra data (block "extra data" such as chest/chest-link pairs): none.
        $extraData = pack('V', 0); // little-endian int, count = 0

        return $blocks . $blockData . $skyLight . $blockLight . $heightMap . $biomes . $extraData;
    }

    /**
     * Build the 8-section sky-light payload from the per-column height map.
     * Block (x, y, z) gets full sky light (0xF nibble) when y >= surface
     * height of its column, otherwise none. The height map uses column order
     * z*16 + x, matching the block layout's y*256 + z*16 + x.
     * @param array<int, int> $heightmap
     */
    private static function buildSkyLight(array $heightmap): string {
        $out = '';
        $lit = str_repeat("\xFF", 256);
        $dark = str_repeat("\x00", 256);
        for ($sy = 0; $sy < self::SECTIONS; $sy++) {
            $bytes = '';
            for ($localY = 0; $localY < 16; $localY++) {
                $worldY = $sy * 16 + $localY;
                $row = '';
                for ($i = 0; $i < 256; $i++) {
                    $surface = max(0, min(127, (int)($heightmap[$i] ?? 0)));
                    $row .= $worldY >= $surface ? $lit[$i] : $dark[$i];
                }
                $bytes .= $row;
            }
            $out .= self::packNibbles($bytes);
        }
        return $out;
    }

    /**
     * Nibble-pack a 4096-byte byte-per-block meta array into 2048 bytes.
     * Block index i (y*256 + z*16 + x, column order) maps to nibble i; even
     * indices land in the low nibble, odd indices in the high nibble.
     */
    private static function packNibbles(string $data): string {
        $out = '';
        $len = strlen($data);
        for ($i = 0; $i + 1 < $len; $i += 2) {
            $out .= chr((ord($data[$i]) & 0x0F) | ((ord($data[$i + 1]) & 0x0F) << 4));
        }
        // Odd-length input: pad the trailing nibble.
        if ($len % 2 === 1) {
            $out .= chr(ord($data[$len - 1]) & 0x0F);
        }
        return $out;
    }
}

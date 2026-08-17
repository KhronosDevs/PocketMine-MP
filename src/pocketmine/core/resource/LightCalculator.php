<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

use function chr;
use function ord;

/**
 * Computes per-chunk light arrays from the flat block grid.
 *
 * Sky light: column-based falloff from 15 at the top of the world, reduced
 * by each block's opacity as the ray travels down (air/glass pass fully,
 * leaves/water dim by 1, opaque blocks cut it to 0). This replaces the old
 * "everything is lit" stub so underground areas are actually dark.
 *
 * Block light: breadth-first flood from every emitting block (torch,
 * glowstone, lava, fire, lit furnace, ...). A block's light level is the
 * max of its own emission and (neighbour level - 1 - target opacity), so
 * light spreads through air and glass, dims through leaves/water and stops
 * at opaque blocks - vanilla behaviour, chunk-local like legacy PMMP.
 *
 * Output matches the store's packed nibble layout: 16 sections x 2048 bytes
 * (block index = (y&15)*256 + z*16 + x; nibble = index; byte = index>>1,
 * even index in the low nibble, odd in the high nibble).
 */
final class LightCalculator {
    private function __construct() {
    }

    /**
     * @param string $blocks 65536-byte flat block grid (Y-major).
     * @return array{0: string, 1: string} [skyLight, blockLight] packed nibble strings.
     */
    /**
     * @param bool $hasSky whether this world has a sky (false = nether: sky
     * light is always 0, only block light BFS runs).
     * @return array{0: string, 1: string} [skyLight, blockLight]
     */
    public static function calculate(string $blocks, BlockRegistry $registry, bool $hasSky = true): array {
        // Precompute the per-block-id tables ONCE: the registry's get() does
        // an array_merge per call, and we touch every one of the 65K blocks
        // several times (sky falloff + BFS neighbourhood), so resolving each
        // block through the registry would cost ~450K array_merges. Two small
        // plain arrays make each lookup a single index into an int.
        static $opacity = null;
        static $emission = null;
        if ($opacity === null || $emission === null) {
            $opacity = [];
            $emission = [];
            for ($id = 0; $id < 256; $id++) {
                $opacity[$id] = $registry->getLightOpacity($id);
                $emission[$id] = $registry->getLightLevel($id);
            }
        }

        // Unpack to a flat id grid for fast repeated lookups.
        $ids = [];
        $len = strlen($blocks);
        for ($i = 0; $i < $len; $i++) {
            $ids[$i] = ord($blocks[$i]);
        }

        $sky = array_fill(0, 65536, 0);
        $block = array_fill(0, 65536, 0);

        // --- Sky light: per-column falloff from the top. ---
        // Worlds without a sky (the nether) get no sky light at all: the
        // column is dark from bedrock to ceiling and only block emitters
        // (lava, glowstone, torches) light it.
        if ($hasSky) {
            for ($x = 0; $x < 16; $x++) {
                for ($z = 0; $z < 16; $z++) {
                    $level = 15;
                    for ($y = 255; $y >= 0; $y--) {
                        $idx = ($y << 8) | ($z << 4) | $x;
                        $sky[$idx] = $level;
                        $level -= $opacity[$ids[$idx]];
                        if ($level < 0) {
                            $level = 0;
                        }
                    }
                }
            }
        }

        // --- Block light: BFS flood from every emitter. ---
        /** @var list<array{0: int, 1: int}> $queue [idx, level] */
        $queue = [];
        foreach ($ids as $idx => $id) {
            $em = $emission[$id];
            if ($em > 0) {
                $block[$idx] = $em;
                $queue[] = [$idx, $em];
            }
        }
        $head = 0;
        while ($head < count($queue)) {
            [$idx, $level] = $queue[$head++];
            $x = $idx & 15;
            $z = ($idx >> 4) & 15;
            $y = $idx >> 8;
            // Six neighbours.
            $neighbours = [];
            if ($x > 0) {
                $neighbours[] = $idx - 1;
            }
            if ($x < 15) {
                $neighbours[] = $idx + 1;
            }
            if ($z > 0) {
                $neighbours[] = $idx - 16;
            }
            if ($z < 15) {
                $neighbours[] = $idx + 16;
            }
            if ($y > 0) {
                $neighbours[] = $idx - 256;
            }
            if ($y < 255) {
                $neighbours[] = $idx + 256;
            }
            foreach ($neighbours as $n) {
                $next = $level - 1 - $opacity[$ids[$n]];
                if ($next > $block[$n]) {
                    $block[$n] = $next;
                    $queue[] = [$n, $next];
                }
            }
        }

        return [self::pack($sky), self::pack($block)];
    }

    /** Pack 65536 per-block levels into 16 x 2048 nibble bytes. */
    private static function pack(array $light): string {
        $out = '';
        for ($sy = 0; $sy < 16; $sy++) {
            $section = '';
            for ($i = 0; $i < 2048; $i++) {
                $low = $light[$sy * 4096 + $i * 2] & 0x0F;
                $high = $light[$sy * 4096 + $i * 2 + 1] & 0x0F;
                $section .= chr(($high << 4) | $low);
            }
            $out .= $section;
        }
        return $out;
    }
}

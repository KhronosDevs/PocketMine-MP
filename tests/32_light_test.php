<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\core\resource\BlockRegistry;
use pocketmine\core\resource\LightCalculator;

test('sky light falls off below an opaque surface', function (): void {
    // A 65536-byte block grid: air up to y=10, then stone from y=10 down.
    $blocks = str_repeat("\x00", 65536);
    for ($y = 0; $y <= 10; $y++) {
        for ($z = 0; $z < 16; $z++) {
            for ($x = 0; $x < 16; $x++) {
                $blocks[($y << 8) | ($z << 4) | $x] = "\x01"; // stone
            }
        }
    }
    [$sky, $block] = LightCalculator::calculate($blocks, new BlockRegistry());

    // Unpack: section sy, byte (i>>1), nibble low/high per block index.
    $lightAt = static function (string $packed, int $y, int $z, int $x): int {
        $idx = ($y << 8) | ($z << 4) | $x;
        $byte = ord($packed[($idx >> 1)]);
        return ($idx & 1) === 0 ? $byte & 0x0F : ($byte >> 4) & 0x0F;
    };

    // Above the stone (y=12) full sky light.
    same(15, $lightAt($sky, 12, 5, 5), 'sky light above surface is 15');
    // The surface block receives full light (its top face is exposed).
    same(15, $lightAt($sky, 10, 5, 5), 'sky light reaches the exposed surface block');
    // Just below the surface the opacity has fully absorbed it.
    same(0, $lightAt($sky, 9, 5, 5), 'sky light is 0 one block below the surface');
    // Deep underground stays dark.
    same(0, $lightAt($sky, 3, 5, 5), 'sky light is 0 underground');
    // No emitters: block light all zero.
    same(0, $lightAt($block, 12, 5, 5), 'no block light without emitters');
});

test('torch block light propagates through air with falloff', function (): void {
    $blocks = str_repeat("\x00", 65536); // all air
    // Torch (id 50) at local (7, 20, 7), emits level 14.
    $blocks[(20 << 8) | (7 << 4) | 7] = chr(50);
    [$sky, $block] = LightCalculator::calculate($blocks, new BlockRegistry());

    $lightAt = static function (string $packed, int $y, int $z, int $x): int {
        $idx = ($y << 8) | ($z << 4) | $x;
        $byte = ord($packed[($idx >> 1)]);
        return ($idx & 1) === 0 ? $byte & 0x0F : ($byte >> 4) & 0x0F;
    };

    // Source block carries its own emission (14).
    same(14, $lightAt($block, 20, 7, 7), 'torch block emits 14');
    // 1 block away in air: 13, 2 blocks: 12 (vanilla -1 per block).
    same(13, $lightAt($block, 20, 7, 8), 'adjacent air is 13');
    same(12, $lightAt($block, 20, 7, 9), 'two blocks away is 12');
    // 7 blocks away the falloff continues (chunk-local: within the 16-wide
    // grid a torch never fully dies, matching legacy per-chunk light).
    same(7, $lightAt($block, 20, 7, 14), 'light keeps falling off with distance');
    // Vertical propagation too.
    same(13, $lightAt($block, 21, 7, 7), 'light propagates upward');
});

test('light does not pass through an opaque wall', function (): void {
    $blocks = str_repeat("\x00", 65536);
    $blocks[(20 << 8) | (7 << 4) | 7] = chr(50); // torch at (7, 20, 7)
    // Full-height stone wall across all z at x=9 so light cannot go around.
    for ($y = 0; $y <= 255; $y++) {
        for ($z = 0; $z < 16; $z++) {
            $blocks[($y << 8) | ($z << 4) | 9] = "\x01";
        }
    }
    [$sky, $block] = LightCalculator::calculate($blocks, new BlockRegistry());

    $lightAt = static function (string $packed, int $y, int $z, int $x): int {
        $idx = ($y << 8) | ($z << 4) | $x;
        $byte = ord($packed[($idx >> 1)]);
        return ($idx & 1) === 0 ? $byte & 0x0F : ($byte >> 4) & 0x0F;
    };

    same(0, $lightAt($block, 20, 7, 11), 'stone wall blocks light');
    same(13, $lightAt($block, 20, 7, 8), 'light reaches the wall face (1 block from torch)');
});

exit(runTests());

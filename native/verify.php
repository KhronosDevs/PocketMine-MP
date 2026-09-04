<?php
declare(strict_types=1);

/**
 * Byte-verification for native/kh_native.c against the PHP implementations.
 * Runs on real generated chunks (overworld + nether, several seeds) plus
 * adversarial synthetic data. Exits non-zero on any mismatch.
 */

require dirname(__DIR__) . '/autoload.php';

use pocketmine\adapter\driven\storage\JavaBlockTranslator;
use pocketmine\adapter\driven\worldgen\ParallelGeneratorAdapter;
use pocketmine\core\resource\BlockRegistry;
use pocketmine\core\resource\LightCalculator;
use pocketmine\core\resource\NativeAccel;
use pocketmine\protocol\ChunkSerializer;

$kernel = \pocketmine\bootstrap();
$kernel->setAutoShutdownOnRun(false);
$kernel->run(1);

$blocks = $kernel->getResourceRegistry()->get(BlockRegistry::class);

if (!NativeAccel::available()) {
    fwrite(STDERR, "NativeAccel unavailable — cannot verify\n");
    exit(1);
}

$failures = 0;
$check = function (string $label, bool $ok) use (&$failures): void {
    if ($ok) {
        echo "ok   $label\n";
    } else {
        $failures++;
        echo "FAIL $label\n";
    }
};

// ---- 1. Light: real overworld + nether chunks vs LightCalculator ----
$opacity = [];
$emission = [];
for ($id = 0; $id < 256; $id++) {
    $opacity[$id] = $blocks->getLightOpacity($id);
    $emission[$id] = $blocks->getLightLevel($id);
}

$cases = [
    [10, -20, 12345, 'normal', true],
    [5, 5, 999, 'normal', true],
    [-30, 12, 1, 'normal', true],
    [3, 3, 777, 'nether', false],
    [-12, 40, 2024, 'normal', true],
    [0, 0, 42, 'normal', true],
];
foreach ($cases as [$cx, $cz, $seed, $type, $hasSky]) {
    $chunk = ParallelGeneratorAdapter::generateChunkPure($cx, $cz, $type, $seed);
    $chunk = ParallelGeneratorAdapter::populateChunkPure($cx, $cz, $chunk, $seed);

    // Build the flat block grid from the chunk's sections (the store layout).
    $raw = str_repeat("\x00", 65536);
    foreach ($chunk->sections as $section) {
        $sy = (int)$section['y'];
        if ($sy < 0 || $sy > 15) {
            continue;
        }
        $raw = substr_replace($raw, (string)$section['blocks'], $sy * 4096, 4096);
    }

    $php = LightCalculator::calculate($raw, $blocks, $hasSky);
    $ffi = NativeAccel::lightCalculate($raw, $opacity, $emission, $hasSky);
    $label = sprintf('light %s(%d,%d) seed=%d sky=%s', $type, $cx, $cz, $seed, $hasSky ? 'yes' : 'no');
    if ($ffi === null) {
        $check($label . ' [ffi returned null]', false);
        continue;
    }
    $check($label, $php[0] === $ffi[0] && $php[1] === $ffi[1]);
}

// Adversarial light: all air, all opaque, checkerboard emitters.
foreach (['all-air' => str_repeat("\x00", 65536), 'all-stone' => str_repeat("\x01", 65536)] as $name => $raw) {
    $php = LightCalculator::calculate($raw, $blocks, true);
    $ffi = NativeAccel::lightCalculate($raw, $opacity, $emission, true);
    $check("light $name", $ffi !== null && $php[0] === $ffi[0] && $php[1] === $ffi[1]);
}

// ---- 2. Noise: octaves vs smoothNoise ----
$ref = new ReflectionClass(ParallelGeneratorAdapter::class);
$sn = $ref->getMethod('smoothNoise');
$sn->setAccessible(true);

$shifts = [9, 7, 5, 8, 8];
$xors = [0, 0x27D4EB2F, 0x6D2B79F5, 0x4F1BBCDC, 0x11D8E2A9];
foreach ([[10, -20, 12345], [5, 5, 999], [-30, 12, 1], [-500, 300, 0x7FFFFFFF]] as [$cx, $cz, $seed]) {
    $oct = NativeAccel::noiseOctaves($cx, $cz, $seed, $shifts, $xors);
    $label = "noise octaves chunk($cx,$cz) seed=$seed";
    if ($oct === null) {
        $check($label . ' [ffi returned null]', false);
        continue;
    }
    $ok = true;
    $checked = 0;
    for ($bz = 0; $bz < 16 && $ok; $bz++) {
        for ($bx = 0; $bx < 16 && $ok; $bx++) {
            $wx = $cx * 16 + $bx;
            $wz = $cz * 16 + $bz;
            for ($i = 0; $i < 5; $i++) {
                $expected = $sn->invoke(null, $wx, $wz, $seed ^ $xors[$i], $shifts[$i]);
                if ($oct[$bz * 16 + $bx][$i] !== $expected) {
                    $ok = false;
                    printf("  diff at col(%d,%d) octave %d: php=%d ffi=%d\n", $bx, $bz, $i, $expected, $oct[$bz * 16 + $bx][$i]);
                    break 3;
                }
                $checked++;
            }
        }
    }
    $check($label . " ($checked values)", $ok);
}

// ---- 3. packNibbles ----
$refSer = new ReflectionClass(ChunkSerializer::class);
$pack = $refSer->getMethod('packNibbles');
$pack->setAccessible(true);
$build = $refSer->getMethod('buildSkyLight');
$build->setAccessible(true);

$samples = [
    str_repeat("\x00", 4096),
    str_repeat("\xFF", 4096),
    random_bytes(4096),
    random_bytes(8192),
    random_bytes(123), // odd length
];
foreach ($samples as $i => $data) {
    $php = $pack->invoke(null, $data);
    $ffi = NativeAccel::packNibbles($data);
    $label = "packNibbles sample#$i (len=" . strlen($data) . ')' . ($php === $ffi ? '' : ' [php=' . strlen($php) . ' ffi=' . ($ffi === null ? 'null' : strlen($ffi)) . ']');
    $check($label, $ffi !== null && $php === $ffi);
}

// ---- 3b. unpackNibbles (RegionStorageAdapter) vs pure-PHP reference ----
$unpackPhp = function (string $nibbles): string {
    $len = strlen($nibbles);
    $out = str_repeat("\x00", $len * 2);
    for ($i = 0; $i < $len; $i++) {
        $byte = ord($nibbles[$i]);
        $out[$i * 2] = chr($byte & 0x0F);
        $out[$i * 2 + 1] = chr(($byte >> 4) & 0x0F);
    }
    return $out;
};
foreach ($samples as $i => $data) {
    // pack first so the nibble layout matches a real round-trip
    $nibbles = $pack->invoke(null, $data);
    $php = $unpackPhp($nibbles);
    $ffi = NativeAccel::unpackNibbles($nibbles);
    $label = "unpackNibbles sample#$i (in=" . strlen($nibbles) . ')' . ($php === $ffi ? '' : ' [ffi=' . ($ffi === null ? 'null' : strlen($ffi)) . ']');
    $check($label, $ffi !== null && $php === $ffi);
}

// ---- 3c. Nether heights batch (netherHeights) vs per-column smoothNoise ----
$refGen = new ReflectionClass(ParallelGeneratorAdapter::class);
$heights = $refGen->getMethod('netherHeights');
$heights->setAccessible(true);
foreach ([[10, -20, 12345], [5, 5, 999], [-30, 12, 1], [-500, 300, 0x7FFFFFFF]] as [$cx, $cz, $seed]) {
    $got = $heights->invoke(null, $cx, $cz, $seed);
    $ok = is_array($got) && count($got) === 256;
    if ($ok) {
        for ($bz = 0; $bz < 16 && $ok; $bz++) {
            for ($bx = 0; $bx < 16 && $ok; $bx++) {
                $wx = $cx * 16 + $bx;
                $wz = $cz * 16 + $bz;
                $expected = 52
                    + intdiv(($sn->invoke(null, $wx, $wz, $seed ^ 0x6E5C2F, 6) - 32768) * 40, 65536)
                    + intdiv(($sn->invoke(null, $wx, $wz, $seed ^ 0x3D1B7A, 4) - 32768) * 16, 65536);
                $expected = max(36, min(106, $expected));
                if ($got[$bz * 16 + $bx] !== $expected) {
                    $ok = false;
                    printf("  diff netherHeights chunk(%d,%d) col(%d,%d): php=%d ffi=%d\n", $cx, $cz, $bx, $bz, $expected, $got[$bz * 16 + $bx]);
                    break 2;
                }
            }
        }
    }
    $check("netherHeights chunk($cx,$cz) seed=$seed", $ok);
}

// ---- 3d. Nether cave slices (netherCaveSlices) vs per-column smoothNoise ----
$caveSlices = $refGen->getMethod('netherCaveSlices');
$caveSlices->setAccessible(true);
foreach ([[10, -20, 12345], [5, 5, 999], [-30, 12, 1]] as [$cx, $cz, $seed]) {
    $got = $caveSlices->invoke(null, $cx, $cz, $seed);
    $ok = is_array($got) && count($got) === 128;
    if ($ok) {
        foreach ([0, 1, 31, 32, 64, 100, 126, 127] as $y) {
            if (!$ok) {
                break;
            }
            for ($bz = 0; $bz < 16 && $ok; $bz++) {
                for ($bx = 0; $bx < 16 && $ok; $bx++) {
                    $wx = $cx * 16 + $bx;
                    $wz = $cz * 16 + $bz;
                    $expected = $sn->invoke(null, $wx ^ (($y * 7919) & 0x7FFFFFFF), $wz, $seed ^ 0x5B4C2A91, 5);
                    if ($got[$y][$bz * 16 + $bx] !== $expected) {
                        $ok = false;
                        printf("  diff netherCaveSlices chunk(%d,%d) y=%d col(%d,%d): php=%d ffi=%d\n", $cx, $cz, $y, $bx, $bz, $expected, $got[$y][$bz * 16 + $bx]);
                        break 3;
                    }
                }
            }
        }
    }
    $check("netherCaveSlices chunk($cx,$cz) seed=$seed", $ok);
}

// ---- 4. buildSkyLight ----
foreach ([[10, -20, 12345, 'normal'], [0, 0, 42, 'normal'], [-30, 12, 1, 'normal']] as [$cx, $cz, $seed, $type]) {
    $chunk = ParallelGeneratorAdapter::populateChunkPure($cx, $cz, ParallelGeneratorAdapter::generateChunkPure($cx, $cz, $type, $seed), $seed);
    $php = $build->invoke(null, $chunk->heightmap);
    $ffi = NativeAccel::buildSkyLight($chunk->heightmap);
    $check("buildSkyLight chunk($cx,$cz)", $ffi !== null && $php === $ffi);
}
// Adversarial heightmaps.
foreach ([[array_fill(0, 256, 0), 'all-zero'], [array_fill(0, 256, 200), 'all-high'], [array_fill(0, 256, 127), 'all-127']] as [$hm, $name]) {
    $php = $build->invoke(null, $hm);
    $ffi = NativeAccel::buildSkyLight($hm);
    $check("buildSkyLight $name", $ffi !== null && $php === $ffi);
}

// ---- 5. Java world import: sanitize + palette decode ----
$mkSection = function (int $seed, bool $javaFlavored): array {
    mt_srand($seed);
    $blocks = '';
    $data = '';
    $pe = [0, 1, 2, 3, 4, 5, 12, 13, 17, 18, 22, 24, 35, 43, 44, 49, 50, 51, 62, 67, 68, 80, 82, 85, 87, 90, 98, 109, 117, 139, 140, 155, 156, 171];
    $javaOnly = [119, 122, 130, 137, 138, 160, 166, 168, 169, 176, 177];
    for ($i = 0; $i < 4096; $i++) {
        $id = $javaFlavored && $i % 23 === 0
            ? $javaOnly[mt_rand(0, count($javaOnly) - 1)]
            : $pe[mt_rand(0, count($pe) - 1)];
        $blocks .= chr($id);
        $data .= chr(mt_rand(0, 15));
    }
    return [$blocks, $data];
};

$sanitizeCases = [
    'PE-valid ids, random meta' => $mkSection(7, false),
    'Java-only ids every 23rd block' => $mkSection(7, true),
    'all stone' => [str_repeat("\x01", 4096), str_repeat("\x00", 4096)],
    'log axis 12 (bark) on every log' => [str_repeat("\x11", 4096), str_repeat("\x0C", 4096)],
    'leaves with decay flags' => [str_repeat("\x12", 4096), str_repeat("\x0F", 4096)],
];
foreach ($sanitizeCases as $name => [$blocks, $data]) {
    $php = JavaBlockTranslator::sanitizeSection($blocks, $data);
    $ffi = NativeAccel::javaSanitize($blocks, $data);
    $label = "javaSanitize $name";
    if ($ffi === null) {
        $check($label . ' [ffi returned null]', false);
        continue;
    }
    $check($label, $php[0] === $ffi[0] && $php[1] === $ffi[1] && $php[2] === ($php[0] !== $blocks || $php[1] !== $data));
}

$namePool = [
    'minecraft:air', 'minecraft:stone', 'minecraft:grass_block', 'minecraft:dirt',
    'minecraft:cobblestone', 'minecraft:oak_planks', 'minecraft:oak_log', 'minecraft:oak_leaves',
    'minecraft:water', 'minecraft:gravel', 'minecraft:sand', 'minecraft:glowstone',
    'minecraft:coal_ore', 'minecraft:iron_ore', 'minecraft:torch', 'minecraft:tall_grass',
    'minecraft:stone_brick_stairs', 'minecraft:white_wool', 'minecraft:acacia_log',
    'minecraft:deepslate_coal_ore', 'minecraft:potted_poppy', 'minecraft:end_portal_frame',
];
$packIndices = function (array $indices, int $bits, bool $continuous): array {
    $longs = [];
    $perWord = intdiv(64, $bits);
    foreach ($indices as $k => $idx) {
        if ($continuous) {
            $word = intdiv($k * $bits, 64);
            $off = ($k * $bits) & 63;
        } else {
            $word = intdiv($k, $perWord);
            $off = ($k % $perWord) * $bits;
        }
        if (!isset($longs[$word])) {
            $longs[$word] = 0;
        }
        $longs[$word] |= $idx << $off;
    }
    ksort($longs);
    return array_values($longs);
};
foreach ([[4, false], [6, false], [6, true], [9, false], [2, false], [2, true]] as [$npal, $continuous]) {
    // mirror JavaBlockTranslator::decodePaletteSection bit-width rules
    $bits = $npal > 1 ? max(4, (int)ceil(log($npal, 2))) : 4;
    mt_srand(11 + $npal * 7);
    $states = [];
    $resolved = [];
    for ($i = 0; $i < $npal; $i++) {
        $n = $namePool[$i % count($namePool)];
        $states[] = [$n, []];
        $resolved[] = JavaBlockTranslator::nameToState($n, []);
    }
    $indices = [];
    for ($k = 0; $k < 4096; $k++) {
        $indices[] = mt_rand(0, $npal - 1);
    }
    $longs = $packIndices($indices, $bits, $continuous);
    $php = JavaBlockTranslator::decodePaletteSection($states, $longs, $continuous);
    $ffi = NativeAccel::javaPaletteFill($longs, $bits, $continuous, $resolved);
    $label = "javaPaletteFill npal=$npal bits=$bits " . ($continuous ? 'continuous' : 'per-word');
    if ($ffi === null) {
        $check($label . ' [ffi returned null]', false);
        continue;
    }
    $check($label, $php[0] === $ffi[0] && $php[1] === $ffi[1]);
}

$kernel->shutdown();
echo $failures === 0 ? "\nALL VERIFIED: byte-identical across light/noise/pack/sky-light/java-import\n" : "\n$failures FAILURES\n";
exit($failures === 0 ? 0 : 1);

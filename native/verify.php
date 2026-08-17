<?php
declare(strict_types=1);

/**
 * Byte-verification for native/kh_native.c against the PHP implementations.
 * Runs on real generated chunks (overworld + nether, several seeds) plus
 * adversarial synthetic data. Exits non-zero on any mismatch.
 */

require dirname(__DIR__) . '/autoload.php';

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

$kernel->shutdown();
echo $failures === 0 ? "\nALL VERIFIED: byte-identical across light/noise/pack/sky-light\n" : "\n$failures FAILURES\n";
exit($failures === 0 ? 0 : 1);

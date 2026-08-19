#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bench/10_cpt_before_after.php
 *
 * Benchmark CPT scalability: before (L7, no cache) vs after (L3 + compression cache).
 * Uses realistic terrain data (~8 KB compressed).
 *
 * Usage: ./bin/php7/bin/php bench/10_cpt_before_after.php [rounds]
 */

require_once __DIR__ . '/../vendor/autoload.php';

$rounds = (int)($argv[1] ?? 20);

echo "=== CPT Scalability: Before vs After ===\n";
echo "Rounds: $rounds\n\n";

// --- Build realistic chunk wire payload ---
mt_srand(42);
$blocks = '';
$blockData = '';
$skyLight = '';
$blockLight = '';

for ($section = 0; $section < 8; $section++) {
    $yBase = $section * 16;
    for ($i = 0; $i < 4096; $i++) {
        $localY = $i >> 8;
        $absY = $yBase + $localY;
        if ($absY > 63) {
            $blocks .= (mt_rand(0, 3) === 0) ? chr(mt_rand(6, 17)) : "\x00";
        } elseif ($absY > 50) {
            $blocks .= (mt_rand(0, 7) === 0) ? chr(2) : chr(3);
        } elseif ($absY > 0) {
            $blocks .= (mt_rand(0, 63) === 0) ? chr(mt_rand(14, 56)) : chr(1);
        } else {
            $blocks .= chr(7);
        }
    }
    $bd = '';
    for ($i = 0; $i < 2048; $i++) {
        $bd .= (mt_rand(0, 15) === 0) ? chr(mt_rand(0, 15)) : "\x00";
    }
    $blockData .= $bd;
    $sl = '';
    for ($i = 0; $i < 2048; $i++) {
        $localY = ($i * 2) >> 8;
        $absY = $yBase + $localY;
        $sl .= ($absY > 63) ? "\xff" : "\x00";
    }
    $skyLight .= $sl;
    $bl = '';
    for ($i = 0; $i < 2048; $i++) {
        $bl .= (mt_rand(0, 255) === 0) ? chr(mt_rand(8, 15)) : "\x00";
    }
    $blockLight .= $bl;
}

$heightmap = str_repeat("\x40", 256);
$biomes = '';
for ($i = 0; $i < 256; $i++) {
    $biomes .= pack('N', 0x7FB238);
}

$wirePayload = $blocks . $blockData . $skyLight . $blockLight . $heightmap . $biomes . pack('V', 0);
$encodedChunk = chr(0x3a) . pack('N', 0) . pack('N', 0) . chr(0) . $wirePayload;
$encodedSize = strlen($encodedChunk);

// Simulate sendChunkBatch: pack('N', len) + encodedChunk → zlib → BatchPacket
function compressL7(string $encodedChunk): string {
    $inner = pack('N', strlen($encodedChunk)) . $encodedChunk;
    $compressed = zlib_encode($inner, ZLIB_ENCODING_DEFLATE, 7);
    $batch = new \pocketmine\protocol\BatchPacket();
    $batch->payload = $compressed;
    $batch->encode();
    return $batch->getBuffer();
}

function compressL3(string $encodedChunk): string {
    $inner = pack('N', strlen($encodedChunk)) . $encodedChunk;
    $compressed = zlib_encode($inner, ZLIB_ENCODING_DEFLATE, 3);
    $batch = new \pocketmine\protocol\BatchPacket();
    $batch->payload = $compressed;
    $batch->encode();
    return $batch->getBuffer();
}

// Warmup
for ($i = 0; $i < 100; $i++) { compressL7($encodedChunk); compressL3($encodedChunk); }

// --- Measure per-chunk cost ---
echo "--- Per-chunk compression cost ---\n";

$times = [];
for ($r = 0; $r < $rounds; $r++) {
    $t = hrtime(true);
    for ($i = 0; $i < 100; $i++) { compressL7($encodedChunk); }
    $times[] = (hrtime(true) - $t) / 1e3 / 100;
}
sort($times);
$l7Cost = $times[(int)(count($times) * 0.5)];

$times = [];
for ($r = 0; $r < $rounds; $r++) {
    $t = hrtime(true);
    for ($i = 0; $i < 100; $i++) { compressL3($encodedChunk); }
    $times[] = (hrtime(true) - $t) / 1e3 / 100;
}
sort($times);
$l3Cost = $times[(int)(count($times) * 0.5)];

printf("  Before (L7):  %7.1f µs/chunk\n", $l7Cost);
printf("  After (L3):   %7.1f µs/chunk\n", $l3Cost);
printf("  Speedup:      %.1fx\n", $l7Cost / $l3Cost);

// Compressed sizes
$inner7 = pack('N', $encodedSize) . $encodedChunk;
$compressed7 = zlib_encode($inner7, ZLIB_ENCODING_DEFLATE, 7);
$inner3 = pack('N', $encodedSize) . $encodedChunk;
$compressed3 = zlib_encode($inner3, ZLIB_ENCODING_DEFLATE, 3);
printf("  Size L7: %d bytes, Size L3: %d bytes (%.1fx larger)\n",
    strlen($compressed7), strlen($compressed3), strlen($compressed3) / strlen($compressed7));

// --- Tick cost comparison ---
echo "\n=== Tick Cost: Before (L7, no cache) vs After (L3 + cache) ===\n";
echo "50ms tick budget. ECS=1.08ms, network=0.38ms, remaining=48.5ms.\n\n";

$budget = 48.5;
$players = [1, 2, 3, 5];
$cptValues = [2, 4, 6, 8, 10, 12];

// Before: L7, no cache — each player compresses independently
// After: L3, with cache — each UNIQUE chunk compressed once, shared across players

echo sprintf("  %-8s  %-30s  %-30s\n", "CPT", "Before (L7, no cache)", "After (L3 + cache)");
echo "  " . str_repeat("-", 70) . "\n";

foreach ($cptValues as $cpt) {
    foreach ($players as $p) {
        // Before: each player compresses CPT chunks
        $beforeCompressions = $p * $cpt;
        $beforeCost = $beforeCompressions * $l7Cost / 1000;

        // After: compress unique chunks only (assume 50% overlap for nearby players)
        $uniqueChunks = max($cpt, (int)($p * $cpt * 0.6)); // 40% overlap
        $afterCost = $uniqueChunks * $l3Cost / 1000;

        $beforeStatus = $beforeCost <= $budget ? 'OK' : 'FAIL';
        $afterStatus = $afterCost <= $budget ? 'OK' : 'FAIL';
        $improvement = $beforeCost / max($afterCost, 0.01);

        printf("  CPT=%-3d %dP: %6.1f ms [%s]          %6.1f ms [%s]  (%.1fx)\n",
            $cpt, $p, $beforeCost, $beforeStatus, $afterCost, $afterStatus, $improvement);
    }
    echo "\n";
}

// --- Maximum safe CPT ---
echo "=== Maximum Safe CPT (fits in 48.5ms budget) ===\n\n";

foreach ($players as $p) {
    // Before: find max CPT where p * cpt * l7Cost <= 48500 µs
    $maxBefore = (int)($budget * 1000 / ($p * $l7Cost));

    // After: find max CPT where max(cpt, p*cpt*0.6) * l3Cost <= 48500 µs
    // For small p, it's cpt * l3Cost. For large p, it's p*cpt*0.6*l3Cost.
    $maxAfter = (int)($budget * 1000 / (max(1, $p * 0.6) * $l3Cost));

    printf("  %d player(s): Before CPT=%-2d  →  After CPT=%-2d  (%.1fx more chunks/tick)\n",
        $p, $maxBefore, $maxAfter, $maxAfter / max($maxBefore, 1));
}

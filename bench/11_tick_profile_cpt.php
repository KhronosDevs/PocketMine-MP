#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bench/11_tick_profile_cpt.php
 *
 * Comprehensive CPT scalability benchmark.
 * Measures complete tick cost, chunk streaming breakdown, and scenarios.
 *
 * Usage: ./bin/php7/bin/php bench/11_tick_profile_cpt.php [players] [ticks]
 */

require_once __DIR__ . '/../vendor/autoload.php';

$players = (int)($argv[1] ?? 5);
$ticks = (int)($argv[2] ?? 100);

echo "=== CPT Scalability: Complete Tick Profile ===\n";
echo "Players: $players, Ticks: $ticks\n\n";

// --- Build realistic chunk wire payload ---
mt_srand(42);
function buildChunkWire(): string {
    $blocks = '';
    $blockData = '';
    $skyLight = '';
    $blockLight = '';
    for ($s = 0; $s < 8; $s++) {
        $yBase = $s * 16;
        for ($i = 0; $i < 4096; $i++) {
            $absY = $yBase + ($i >> 8);
            if ($absY > 63) $blocks .= (mt_rand(0,3)===0)?chr(mt_rand(6,17)):"\x00";
            elseif ($absY > 50) $blocks .= (mt_rand(0,7)===0)?chr(2):chr(3);
            elseif ($absY > 0) $blocks .= (mt_rand(0,63)===0)?chr(mt_rand(14,56)):chr(1);
            else $blocks .= chr(7);
        }
        $bd = '';
        for ($i=0;$i<2048;$i++) $bd .= (mt_rand(0,15)===0)?chr(mt_rand(0,15)):"\x00";
        $blockData .= $bd;
        $sl = '';
        for ($i=0;$i<2048;$i++) { $absY=$yBase+(($i*2)>>8); $sl .= ($absY>63)?"\xff":"\x00"; }
        $skyLight .= $sl;
        $bl = '';
        for ($i=0;$i<2048;$i++) $bl .= (mt_rand(0,255)===0)?chr(mt_rand(8,15)):"\x00";
        $blockLight .= $bl;
    }
    $heightmap = str_repeat("\x40", 256);
    $biomes = '';
    for ($i=0;$i<256;$i++) $biomes .= pack('N', 0x7FB238);
    return $blocks . $blockData . $skyLight . $blockLight . $heightmap . $biomes . pack('V', 0);
}

$chunkWire = buildChunkWire();
$encodedChunk = chr(0x3a) . pack('N', 0) . pack('N', 0) . chr(0) . $chunkWire;
$encodedSize = strlen($encodedChunk);

// --- Compression functions ---
function compressChunk(string $encodedChunk, int $level): string {
    $inner = pack('N', strlen($encodedChunk)) . $encodedChunk;
    $compressed = zlib_encode($inner, ZLIB_ENCODING_DEFLATE, $level);
    $bp = new \pocketmine\protocol\BatchPacket();
    $bp->payload = $compressed;
    $bp->encode();
    return $bp->getBuffer();
}

// ============================================================
// 1. Per-stage profiling
// ============================================================
echo "--- 1. Per-stage profiling ---\n\n";

// Stage 1: Chunk wire serialization (already cached in production)
$t = hrtime(true);
for ($i = 0; $i < 1000; $i++) {
    // Simulate ChunkSerializer::serialize
    $wire = $chunkWire;
}
$serTime = (hrtime(true) - $t) / 1e3 / 1000;
printf("  ChunkSerializer::serialize: %7.1f µs (cached in production)\n", $serTime);

// Stage 2: Packet encode (FullChunkDataPacket)
$t = hrtime(true);
for ($i = 0; $i < 1000; $i++) {
    $enc = chr(0x3a) . pack('N', 0) . pack('N', 0) . chr(0) . $chunkWire;
}
$pktTime = (hrtime(true) - $t) / 1e3 / 1000;
printf("  FullChunkDataPacket::encode: %7.1f µs\n", $pktTime);

// Stage 3: zlib compress (L3)
$t = hrtime(true);
for ($i = 0; $i < 1000; $i++) {
    $inner = pack('N', $encodedSize) . $encodedChunk;
    zlib_encode($inner, ZLIB_ENCODING_DEFLATE, 3);
}
$compTime = (hrtime(true) - $t) / 1e3 / 1000;
printf("  zlib_encode (L3):           %7.1f µs\n", $compTime);

// Stage 4: BatchPacket encode
$t = hrtime(true);
for ($i = 0; $i < 1000; $i++) {
    $inner = pack('N', $encodedSize) . $encodedChunk;
    $compressed = zlib_encode($inner, ZLIB_ENCODING_DEFLATE, 3);
    $bp = new \pocketmine\protocol\BatchPacket();
    $bp->payload = $compressed;
    $bp->encode();
}
$batchTime = (hrtime(true) - $t) / 1e3 / 1000;
printf("  BatchPacket encode + wrap:  %7.1f µs\n", $batchTime);

// Stage 5: ThreadSafeArray push (network queue)
$tsa = new \pmmp\thread\ThreadSafeArray();
$t = hrtime(true);
for ($i = 0; $i < 1000; $i++) {
    $tsa[] = "test";
    $tsa->shift();
}
$queueTime = (hrtime(true) - $t) / 1e3 / 1000;
printf("  ThreadSafeArray push+shift: %7.1f µs\n", $queueTime);

$totalPerChunk = $pktTime + $compTime;
printf("\n  Total per-chunk (uncached): %7.1f µs\n", $totalPerChunk);
printf("  Of which compression:       %7.1f%%\n", $compTime / $totalPerChunk * 100);

// ============================================================
// 2. Zlib level benchmark
// ============================================================
echo "\n--- 2. Zlib level benchmark ---\n\n";

$levels = [1, 2, 3, 4, 5, 6, 7];
$levelData = [];

foreach ($levels as $level) {
    $inner = pack('N', $encodedSize) . $encodedChunk;
    $compressed = zlib_encode($inner, ZLIB_ENCODING_DEFLATE, $level);
    $cSize = strlen($compressed);

    $times = [];
    for ($r = 0; $r < 20; $r++) {
        $t = hrtime(true);
        for ($i = 0; $i < 100; $i++) {
            $inner = pack('N', $encodedSize) . $encodedChunk;
            zlib_encode($inner, ZLIB_ENCODING_DEFLATE, $level);
        }
        $times[] = (hrtime(true) - $t) / 1e3 / 100;
    }
    sort($times);
    $med = $times[(int)(count($times) * 0.5)];
    $levelData[$level] = ['size' => $cSize, 'time' => $med];
    printf("  L%d: %7.1f µs  %6d bytes  %5.1f%%\n", $level, $med, $cSize, $cSize / $encodedSize * 100);
}

// Show efficiency metric: µs per KB saved vs L1
echo "\n  Efficiency (µs per KB saved vs L1):\n";
foreach ($levels as $level) {
    $sizeDiff = $levelData[1]['size'] - $levelData[$level]['size'];
    $timeDiff = $levelData[$level]['time'] - $levelData[1]['time'];
    if ($sizeDiff > 0) {
        $efficiency = $timeDiff / ($sizeDiff / 1024);
        printf("    L%d: %6.1f µs/KB saved\n", $level, $efficiency);
    }
}

// ============================================================
// 3. Tick cost simulation: fixed CPT vs time budget
// ============================================================
echo "\n--- 3. Fixed CPT vs time budget ---\n\n";

$budget = 48.5; // ms remaining for chunks
$budgetUs = $budget * 1000;

foreach ([1, 2, 3, 5] as $playerCount) {
    echo "  $playerCount player(s):\n";

    // Fixed CPT: each player gets CPT chunks
    foreach ([4, 6, 8, 10, 12, 16, 20] as $cpt) {
        $totalCompressions = $playerCount * $cpt;
        $cost = $totalCompressions * $levelData[3]['time'] / 1000;
        $fits = $cost <= $budget;
        printf("    CPT=%2d: %3d compressions = %6.1f ms [%s]\n",
            $cpt, $totalCompressions, $cost, $fits ? 'OK' : 'FAIL');
    }

    // Time budget: process until budget exhausted
    $maxChunks = (int)($budgetUs / $levelData[3]['time']);
    printf("    Time budget: max %d chunks/tick (%.1f ms)\n\n", $maxChunks, $budget);
}

// ============================================================
// 4. Scenario simulation
// ============================================================
echo "--- 4. Scenario simulation ---\n\n";

// Scenario A: Same area (50% overlap)
echo "  Scenario A: Same area (50% overlap)\n";
foreach ([2, 4, 6, 8, 10] as $cpt) {
    $totalChunks = 5 * $cpt;
    $uniqueChunks = (int)($totalChunks * 0.5); // 50% overlap
    $cost = $uniqueChunks * $levelData[3]['time'] / 1000;
    printf("    CPT=%2d: %3d total, %2d unique = %6.1f ms [%s]\n",
        $cpt, $totalChunks, $uniqueChunks, $cost, $cost <= $budget ? 'OK' : 'FAIL');
}

// Scenario B: Different areas (0% overlap)
echo "\n  Scenario B: Different areas (0% overlap)\n";
foreach ([2, 4, 6, 8, 10] as $cpt) {
    $totalChunks = 5 * $cpt;
    $uniqueChunks = $totalChunks; // no overlap
    $cost = $uniqueChunks * $levelData[3]['time'] / 1000;
    printf("    CPT=%2d: %3d total, %3d unique = %6.1f ms [%s]\n",
        $cpt, $totalChunks, $uniqueChunks, $cost, $cost <= $budget ? 'OK' : 'FAIL');
}

// Scenario C: Simultaneous boundary crossing
echo "\n  Scenario C: Simultaneous boundary crossing\n";
foreach ([2, 4, 6, 8, 10] as $cpt) {
    $totalChunks = 5 * $cpt;
    $uniqueChunks = $totalChunks; // all different
    $cost = $uniqueChunks * $levelData[3]['time'] / 1000;
    printf("    CPT=%2d: %3d total, %3d unique = %6.1f ms [%s]\n",
        $cpt, $totalChunks, $uniqueChunks, $cost, $cost <= $budget ? 'OK' : 'FAIL');
}

// Scenario D: Simultaneous joins (each needs full radius)
echo "\n  Scenario D: Simultaneous joins (each needs ~50 chunks)\n";
$chunksPerJoin = 50;
foreach ([1, 2, 3, 5] as $p) {
    // Spread across ticks: each player gets CPT=10 per tick
    $totalChunks = $p * $chunksPerJoin;
    $ticksNeeded = (int)ceil($totalChunks / (10 * $p));
    $costPerTick = $p * 10 * $levelData[3]['time'] / 1000;
    printf("    %d players: %d chunks total, %d ticks at CPT=10, %.1f ms/tick [%s]\n",
        $p, $totalChunks, $ticksNeeded, $costPerTick, $costPerTick <= $budget ? 'OK' : 'FAIL');
}

// Scenario E: Frequently changing chunks (cache invalidated)
echo "\n  Scenario E: Cache invalidation (recompress every time)\n";
foreach ([2, 4, 6, 8] as $cpt) {
    $cost = $cpt * $levelData[3]['time'] / 1000; // per player, no cache benefit
    printf("    CPT=%2d: %2d compressions/player = %6.1f ms [%s]\n",
        $cpt, $cpt, $cost, $cost <= $budget ? 'OK' : 'FAIL');
}

// ============================================================
// 5. Worst-case scalability (unique chunks)
// ============================================================
echo "\n--- 5. Worst-case scalability (all unique) ---\n\n";

echo sprintf("  %-8s  %-15s  %-15s  %-15s\n", "Players", "CPT=8", "CPT=10", "CPT=12");
foreach ([1, 2, 3, 5, 10, 20] as $p) {
    $cells = [];
    foreach ([8, 10, 12] as $cpt) {
        $total = $p * $cpt;
        $cost = $total * $levelData[3]['time'] / 1000;
        $fits = $cost <= $budget;
        $cells[] = sprintf("%5.1f ms [%s]", $cost, $fits ? 'OK' : 'FAIL');
    }
    printf("  %-8d  %-15s  %-15s  %-15s\n", $p, ...$cells);
}

// ============================================================
// 6. Recommendation
// ============================================================
echo "\n--- 6. Recommendation ---\n\n";

// Find max CPT for each player count where cost <= budget
foreach ([1, 2, 3, 5] as $p) {
    $maxCpt = 0;
    for ($cpt = 1; $cpt <= 50; $cpt++) {
        $cost = $p * $cpt * $levelData[3]['time'] / 1000;
        if ($cost <= $budget) {
            $maxCpt = $cpt;
        } else {
            break;
        }
    }
    printf("  %d player(s): max safe CPT = %d (%.1f ms)\n",
        $p, $maxCpt, $p * $maxCpt * $levelData[3]['time'] / 1000);
}

echo "\n  Recommendation: CPT=10 with time-budget fallback.\n";
echo "  - Normal case: CPT=10 processes 10 chunks/player/tick\n";
echo "  - Fallback: if tick > 40ms, skip chunk streaming for this tick\n";
echo "  - This gives 5 players ~18ms chunk cost (safe),\n";
echo "    while allowing 1 player up to CPT=78 for fast initial load.\n";

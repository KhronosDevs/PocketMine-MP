#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bench/10_cpt_scalability.php
 *
 * Profile the chunk streaming pipeline and test compression caching.
 *
 * Architecture trace:
 *   1. ChunkStore::getSerializedWire() — caches serialized wire (shared across viewers)
 *   2. sendChunkBatch() — compresses per-player (NOT cached)
 *   3. Each player gets independent zlib_encode() call on same wire payload
 *
 * Root hypothesis: serialization is cached but compression is not.
 * 5 players × same chunk = 1 serialize + 5 compressions.
 *
 * Usage: ./bin/php7/bin/php bench/10_cpt_scalability.php [rounds]
 */

require_once __DIR__ . '/../vendor/autoload.php';

$rounds = (int)($argv[1] ?? 20);

echo "=== CPT Scalability Profile ===\n";
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
$wireSize = strlen($wirePayload);

// Simulate what sendChunkBatch does: encode FullChunkDataPacket + compress
// FullChunkDataPacket::encode() wraps: network_id(1) + chunkX(4) + chunkZ(4) + order(1) + data
function simulateChunkEncode(string $wire, int $cx, int $cz): string {
    // FullChunkDataPacket layout (protocol 84):
    // NETWORK_ID (1 byte) + chunkX (4 byte int) + chunkZ (4 byte int) + order (1 byte) + data
    return chr(0x3a) . pack('N', $cx) . pack('N', $cz) . chr(0) . $wire;
}

function simulateChunkBatch(string $encodedChunk): string {
    // sendChunkBatch: pack('N', len) + encodedChunk → zlib_encode → BatchPacket wrap
    $inner = pack('N', strlen($encodedChunk)) . $encodedChunk;
    $compressed = zlib_encode($inner, ZLIB_ENCODING_DEFLATE, 7);
    // BatchPacket::encode: putInt(len) + put(payload)
    return pack('N', strlen($compressed)) . $compressed;
}

$encodedChunk = simulateChunkEncode($wirePayload, 0, 0);
$encodedSize = strlen($encodedChunk);
$compressedPayload = zlib_encode(pack('N', $encodedSize) . $encodedChunk, ZLIB_ENCODING_DEFLATE, 7);
$compressedSize = strlen($compressedPayload);

echo "Chunk wire payload: $wireSize bytes\n";
echo "FullChunkDataPacket encoded: $encodedSize bytes\n";
echo "Compressed (L7): $compressedSize bytes\n";
echo "Compression ratio: " . round($compressedSize / $encodedSize * 100, 1) . "%\n\n";

// ============================================================
// 1. Per-stage profiling
// ============================================================
echo "--- Stage 1: ChunkSerializer::serialize (serialization) ---\n";
// Serialization is cached in ChunkStore, so we only measure once
$serTimes = [];
for ($r = 0; $r < $rounds; $r++) {
    $t = hrtime(true);
    for ($i = 0; $i < 100; $i++) {
        simulateChunkEncode($wirePayload, $i, 0);
    }
    $serTimes[] = (hrtime(true) - $t) / 1e3 / 100;
}
sort($serTimes);
$serMed = $serTimes[(int)(count($serTimes) * 0.5)];
printf("  Packet encode (wire → FullChunkDataPacket): %7.1f µs/op\n", $serMed);

echo "\n--- Stage 2: zlib_encode (compression) — THE BOTTLENECK ---\n";
$compTimes = [];
for ($r = 0; $r < $rounds; $r++) {
    $t = hrtime(true);
    for ($i = 0; $i < 100; $i++) {
        $inner = pack('N', $encodedSize) . $encodedChunk;
        zlib_encode($inner, ZLIB_ENCODING_DEFLATE, 7);
    }
    $compTimes[] = (hrtime(true) - $t) / 1e3 / 100;
}
sort($compTimes);
$compMed = $compTimes[(int)(count($compTimes) * 0.5)];
printf("  zlib_encode (L7): %7.1f µs/op\n", $compMed);

echo "\n--- Stage 3: BatchPacket encode + wrap ---\n";
$batchTimes = [];
for ($r = 0; $r < $rounds; $r++) {
    $t = hrtime(true);
    for ($i = 0; $i < 100; $i++) {
        simulateChunkBatch($encodedChunk);
    }
    $batchTimes[] = (hrtime(true) - $t) / 1e3 / 100;
}
sort($batchTimes);
$batchMed = $batchTimes[(int)(count($batchTimes) * 0.5)];
printf("  Full sendChunkBatch (encode+compress+wrap): %7.1f µs/op\n", $batchMed);

// ============================================================
// 2. Duplicate compression: same chunk, N players
// ============================================================
echo "\n=== Duplicate Compression Analysis ===\n";
echo "Same chunk sent to N players — how much work is duplicated?\n\n";

foreach ([1, 2, 3, 5] as $players) {
    $times = [];
    for ($r = 0; $r < $rounds; $r++) {
        $t = hrtime(true);
        for ($i = 0; $i < 100; $i++) {
            // Each player triggers independent sendChunkBatch
            for ($p = 0; $p < $players; $p++) {
                simulateChunkBatch($encodedChunk);
            }
        }
        $times[] = (hrtime(true) - $t) / 1e3 / 100;
    }
    sort($times);
    $med = $times[(int)(count($times) * 0.5)];
    $perChunk = $med / $players;
    printf("  %d player(s): %7.1f µs total  (%.1f µs/chunk if cached)\n",
        $players, $med, $perChunk);
}
echo "\n  -> With compression cache: always ~" . round($batchMed) . " µs regardless of player count\n";

// ============================================================
// 3. Zlib level benchmark (speed vs size)
// ============================================================
echo "\n=== Zlib Level Benchmark ===\n";
echo "Speed vs compressed size for different compression levels.\n\n";

$levels = [1, 2, 3, 4, 5, 6, 7];
$levelData = [];

foreach ($levels as $level) {
    // Compressed size
    $inner = pack('N', $encodedSize) . $encodedChunk;
    $compressed = zlib_encode($inner, ZLIB_ENCODING_DEFLATE, $level);
    $cSize = strlen($compressed);

    // Speed
    $times = [];
    for ($r = 0; $r < $rounds; $r++) {
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
    printf("  L%d: %7.1f µs  compressed: %6d bytes  ratio: %5.1f%%\n",
        $level, $med, $cSize, $cSize / $encodedSize * 100);
}

// Show speedup from L7→L1
if (isset($levelData[7], $levelData[1])) {
    $speedup = $levelData[7]['time'] / $levelData[1]['time'];
    $sizeIncrease = $levelData[1]['size'] / $levelData[7]['size'];
    printf("\n  L7→L1 speedup: %.1fx faster, %.1fx larger output\n", $speedup, $sizeIncrease);
}
if (isset($levelData[7], $levelData[3])) {
    $speedup = $levelData[7]['time'] / $levelData[3]['time'];
    $sizeIncrease = $levelData[3]['size'] / $levelData[7]['size'];
    printf("  L7→L3 speedup: %.1fx faster, %.1fx larger output\n", $speedup, $sizeIncrease);
}

// ============================================================
// 4. Simulated tick cost with/without compression cache
// ============================================================
echo "\n=== Tick Cost Simulation ===\n";
echo "Assumes all players need CPT chunks, same world.\n";
echo "Without cache: each player compresses independently.\n";
echo "With cache: each unique chunk compressed once, shared.\n\n";

$chunksPerTick = 6; // CPT=6
$uniqueChunksNeeded = function(int $players, int $cpt): int {
    // Worst case: all players need different chunks
    // Best case: all players need the same chunks (overlap)
    // We model both.
    return $players * $cpt; // worst case: no overlap
};

$uniqueChunksOverlap = function(int $players, int $cpt): int {
    // Realistic: with distance-ordered queuing, nearby players share ~50% of chunks
    $total = $players * $cpt;
    // ~50% overlap for nearby players, ~80% for very close
    return (int)($total * 0.5); // conservative overlap estimate
};

foreach ([1, 2, 3, 5] as $players) {
    $worstUnique = $uniqueChunksNeeded($players, $chunksPerTick);
    $overlapUnique = $uniqueChunksOverlap($players, $chunksPerTick);

    // Without cache: compress once per player per chunk
    $noCacheCost = $players * $chunksPerTick * $batchMed / 1000; // ms

    // With cache (worst case): compress each unique chunk once
    $cacheWorstCost = $worstUnique * $batchMed / 1000;

    // With cache (overlap): compress each unique chunk once
    $cacheOverlapCost = $overlapUnique * $batchMed / 1000;

    printf("  %d player(s):\n", $players);
    printf("    No cache:   %3d compressions = %6.1f ms\n",
        $players * $chunksPerTick, $noCacheCost);
    printf("    Cache (worst, %d unique): %6.1f ms\n",
        $worstUnique, $cacheWorstCost);
    printf("    Cache (overlap, %d unique): %6.1f ms\n",
        $overlapUnique, $cacheOverlapCost);
}

// ============================================================
// 5. Recommended CPT with compression cache
// ============================================================
echo "\n=== CPT Recommendations ===\n";
echo "50ms tick budget. ECS=1.08ms, network=0.38ms, remaining=48.5ms.\n\n";

$budget = 48.5; // ms remaining for chunks

foreach ([1, 2, 3, 5] as $players) {
    echo "  $players player(s):\n";
    foreach ([2, 4, 6, 8, 10, 12] as $cpt) {
        // With cache: compress unique chunks only
        $totalCompressions = $players * $cpt; // worst case
        $cost = $totalCompressions * $batchMed / 1000;
        $fits = $cost <= $budget;
        printf("    CPT=%2d: %3d compressions = %6.1f ms [%s]\n",
            $cpt, $totalCompressions, $cost, $fits ? 'OK' : 'FAIL');
    }
    echo "\n";
}

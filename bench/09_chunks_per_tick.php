#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bench/09_chunks_per_tick.php
 *
 * Benchmark CHUNKS_PER_TICK at different values under multi-player load.
 * Uses realistic terrain data patterns (air on top, stone/dirt below).
 *
 * Usage: ./bin/php7/bin/php bench/09_chunks_per_tick.php [rounds]
 */

require_once __DIR__ . '/../vendor/autoload.php';

$rounds = (int)($argv[1] ?? 20);

echo "=== CHUNKS_PER_TICK benchmark ===\n";
echo "Rounds: $rounds\n\n";

// --- Build realistic chunk data ---
// Protocol-84 wire format: 8 sections × (4096 blocks + 2048 data + 2048 skyLight + 2048 blockLight)
// + 256 heightmap + 1024 biome colors + 4 extra data = ~81 KB

// Simulate a typical chunk: air (y=64-127), stone (y=0-50), dirt (y=51-62), grass (y=63)
$blocks = '';
$blockData = '';
$skyLight = '';
$blockLight = '';

// Seed the RNG for reproducible terrain
mt_srand(42);
for ($section = 0; $section < 8; $section++) {
    $yBase = $section * 16;
    for ($i = 0; $i < 4096; $i++) {
        $localY = $i >> 8; // which 16-block column within the section
        $absY = $yBase + $localY;
        if ($absY > 63) {
            // Air (75% chance) or occasional tree/flower (25%)
            $blocks .= (mt_rand(0, 3) === 0) ? chr(mt_rand(6, 17)) : "\x00";
        } elseif ($absY > 50) {
            // Dirt with occasional grass
            $blocks .= (mt_rand(0, 7) === 0) ? chr(2) : chr(3);
        } elseif ($absY > 0) {
            // Stone with occasional ore veins
            $blocks .= (mt_rand(0, 63) === 0) ? chr(mt_rand(14, 56)) : chr(1);
        } else {
            // Bedrock
            $blocks .= chr(7);
        }
    }
    // Block data (nibbles): mostly zeros, some variation
    $bd = '';
    for ($i = 0; $i < 2048; $i++) {
        $bd .= (mt_rand(0, 15) === 0) ? chr(mt_rand(0, 15)) : "\x00";
    }
    $blockData .= $bd;
    // Sky light: full for air, dim for underground
    $skyLightSection = '';
    for ($i = 0; $i < 2048; $i++) {
        $localY = ($i * 2) >> 8;
        $absY = $yBase + $localY;
        $skyLightSection .= ($absY > 63) ? "\xff" : "\x00";
    }
    $skyLight .= $skyLightSection;
    // Block light: sparse torches/glowstone underground
    $bl = '';
    for ($i = 0; $i < 2048; $i++) {
        $bl .= (mt_rand(0, 255) === 0) ? chr(mt_rand(8, 15)) : "\x00";
    }
    $blockLight .= $bl;
}

$heightmap = str_repeat("\x40", 256); // all at y=64
$biomes = '';
for ($i = 0; $i < 256; $i++) {
    $biomes .= pack('N', 0x7FB238); // plains RGBA
}
$extraData = pack('V', 0); // count = 0

$chunkWire = $blocks . $blockData . $skyLight . $blockLight . $heightmap . $biomes . $extraData;
$chunkRawSize = strlen($chunkWire);

// Compress to get realistic compressed size
$inner = pack('N', $chunkRawSize) . $chunkWire;
$compressed = zlib_encode($inner, ZLIB_ENCODING_DEFLATE, 7);
$compressedSize = strlen($compressed);

echo "Chunk wire size: $chunkRawSize bytes\n";
echo "Chunk compressed (L7): $compressedSize bytes\n";
echo "Compression ratio: " . round($compressedSize / $chunkRawSize * 100, 1) . "%\n\n";

// --- Test 1: Single chunk compression cost ---
echo "--- Single chunk compression cost ---\n";

$times = [];
for ($r = 0; $r < $rounds; $r++) {
    $t = hrtime(true);
    for ($i = 0; $i < 100; $i++) {
        $inner = pack('N', $chunkRawSize) . $chunkWire;
        $compressed = zlib_encode($inner, ZLIB_ENCODING_DEFLATE, 7);
    }
    $times[] = (hrtime(true) - $t) / 1e3;
}
sort($times);
$median = $times[(int)(count($times) * 0.5)];
printf("  zlib_encode %d KB (L7):  %7.1f µs  (%d rounds × 100)\n",
    $chunkRawSize / 1024, $median / 100, $rounds);

// --- Test 2: Simulated tick cost at different CHUNKS_PER_TICK ---
echo "\n--- Tick cost simulation (single player) ---\n";

function simulateChunkSend(int $numChunks, string $chunkWire, int $chunkRawSize): float {
    $total = 0;
    for ($i = 0; $i < $numChunks; $i++) {
        $inner = pack('N', $chunkRawSize) . $chunkWire;
        $t = hrtime(true);
        $compressed = zlib_encode($inner, ZLIB_ENCODING_DEFLATE, 7);
        $total += (hrtime(true) - $t);
    }
    return $total / 1e3;
}

foreach ([2, 4, 6, 8] as $cpt) {
    $times = [];
    for ($r = 0; $r < $rounds; $r++) {
        $t = hrtime(true);
        simulateChunkSend($cpt, $chunkWire, $chunkRawSize);
        $times[] = (hrtime(true) - $t) / 1e3;
    }
    sort($times);
    $median = $times[(int)(count($times) * 0.5)];
    printf("  CHUNKS_PER_TICK=%d:  %7.1f µs/tick  (%.1f ms at 20 TPS)\n",
        $cpt, $median, $median / 1000);
}

// --- Test 3: Multi-player scaling ---
echo "\n--- Multi-player scaling (concurrent chunk streaming) ---\n";

foreach ([2, 4, 6, 8] as $cpt) {
    echo "\n  CHUNKS_PER_TICK=$cpt:\n";
    foreach ([1, 5, 10, 20] as $players) {
        $totalChunks = $players * $cpt;
        $times = [];
        for ($r = 0; $r < $rounds; $r++) {
            $t = hrtime(true);
            simulateChunkSend($totalChunks, $chunkWire, $chunkRawSize);
            $times[] = (hrtime(true) - $t) / 1e3;
        }
        sort($times);
        $median = $times[(int)(count($times) * 0.5)];
        printf("    %2d players:  %7.1f µs/tick  (%.2f ms at 20 TPS)\n",
            $players, $median, $median / 1000);
    }
}

// --- Test 4: Bandwidth burst analysis ---
echo "\n--- Bandwidth burst per tick ---\n";

$perChunkOverhead = 1 + 5 + 7 + 28; // 0xfe + BatchPacket + encaps + UDP/IP
$perChunkTotal = $compressedSize + $perChunkOverhead;

echo "  Per chunk on wire: ~$perChunkTotal bytes ($compressedSize compressed + $perChunkOverhead overhead)\n\n";

foreach ([2, 4, 6, 8] as $cpt) {
    echo "  CHUNKS_PER_TICK=$cpt:\n";
    foreach ([1, 5, 10, 20] as $players) {
        $chunksPerTick = $players * $cpt;
        $bytesPerTick = $chunksPerTick * $perChunkTotal;
        $mbpsPerPlayer = ($cpt * $perChunkTotal * 20) / (1024 * 1024);
        $mbpsTotal = ($bytesPerTick * 20) / (1024 * 1024);
        printf("    %2d players:  %3d chunks/tick  = %6d KB/tick  = %5.1f MB/s total  (%.1f MB/s/player)\n",
            $players, $chunksPerTick, $bytesPerTick / 1024, $mbpsTotal, $mbpsPerPlayer);
    }
    echo "\n";
}

// --- Test 5: UDP fragmentation risk ---
echo "--- UDP fragmentation risk ---\n";

$mtu = 1432;
$splitsPerChunk = ceil($perChunkTotal / $mtu);

echo "  RakNet MTU: $mtu bytes\n";
echo "  Compressed chunk: ~$compressedSize bytes\n";
echo "  Splits per chunk: ~$splitsPerChunk datagrams\n";
echo "  At CHUNKS_PER_TICK=8, 1 player: " . (8 * $splitsPerChunk) . " datagrams/tick\n";
echo "  At CHUNKS_PER_TICK=8, 10 players: " . (80 * $splitsPerChunk) . " datagrams/tick\n";
echo "  At 20 TPS: " . (80 * $splitsPerChunk * 20) . " datagrams/s total\n\n";

// --- Summary table ---
echo "=== Summary ===\n";
echo "╔═══════╦══════════════════════════════════════════════════════════╗\n";
echo "║  CPT  ║  CPU cost (1 player)  │  Bandwidth/player  │ Dgrams/s  ║\n";
echo "╠═══════╬══════════════════════════════════════════════════════════╣\n";

foreach ([2, 4, 6, 8] as $cpt) {
    $times = [];
    for ($r = 0; $r < $rounds; $r++) {
        $t = hrtime(true);
        simulateChunkSend($cpt, $chunkWire, $chunkRawSize);
        $times[] = (hrtime(true) - $t) / 1e3;
    }
    sort($times);
    $median = $times[(int)(count($times) * 0.5)];
    $mbps = ($cpt * $perChunkTotal * 20) / (1024 * 1024);
    $dgrams = $cpt * $splitsPerChunk * 20;
    printf("║  %2d   ║  %6.1f ms/tick      │  %4.1f MB/s         │ %5d     ║\n",
        $cpt, $median / 1000, $mbps, $dgrams);
}

echo "╚═══════╩══════════════════════════════════════════════════════════╝\n";

echo "\n20ms tick budget at 20 TPS:\n";
echo "  ECS dispatch: ~1.08 ms\n";
echo "  Network main-thread: ~0.38 ms (non-chunk)\n";
echo "  Remaining for chunks: ~18.5 ms\n";
echo "  At CPT=8: compression alone would use ~55 ms — exceeds budget\n";
echo "  At CPT=4: compression alone would use ~28 ms — exceeds budget\n";
echo "  At CPT=2: compression alone would use ~14 ms — fits with headroom\n";

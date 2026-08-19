#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bench/08_network_profile.php
 *
 * Profile the cross-thread streaming and main-thread network overhead.
 * Measures:
 * 1. ThreadSafeArray push/shift (the cross-thread queue)
 * 2. Double EncapsulatedPacket::fromBinary (once on RakLib thread, once on main thread)
 * 3. handleInbound batch decompression + inner-packet loop
 * 4. flushOutbound: zlib_encode + BatchPacket encode
 *
 * Usage: ./bin/php7/bin/php bench/08_network_profile.php [rounds]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use raklib\Binary;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\PacketReliability;
use pmmp\thread\ThreadSafeArray;

$rounds = (int)($argv[1] ?? 30);

echo "=== Network pipeline profile ===\n";
echo "Rounds: $rounds\n\n";

// --- Test 1: ThreadSafeArray push/shift throughput ---
echo "--- ThreadSafeArray push/shift (cross-thread queue) ---\n";

// Simulate the queue pattern: push N packets, then shift them all back
$payloadSizes = [64, 256, 1024, 4096];
foreach ($payloadSizes as $size) {
    $data = str_repeat("X", $size);
    $tsa = new ThreadSafeArray();

    // Warmup
    for ($i = 0; $i < 100; $i++) {
        $tsa[] = $data;
        $tsa->shift();
    }

    // Measure push throughput
    $pushTimes = [];
    for ($r = 0; $r < $rounds; $r++) {
        $t = hrtime(true);
        for ($i = 0; $i < 1000; $i++) {
            $tsa[] = $data;
        }
        $pushTimes[] = (hrtime(true) - $t) / 1e3;
    }
    // Measure shift throughput
    $shiftTimes = [];
    for ($r = 0; $r < $rounds; $r++) {
        // Pre-fill
        for ($i = 0; $i < 1000; $i++) { $tsa[] = $data; }
        $t = hrtime(true);
        for ($i = 0; $i < 1000; $i++) {
            $tsa->shift();
        }
        $shiftTimes[] = (hrtime(true) - $t) / 1e3;
    }

    sort($pushTimes); sort($shiftTimes);
    printf("  %4d B payload:  push: %6.1f ns/op  shift: %6.1f ns/op\n",
        $size,
        $pushTimes[(int)(count($pushTimes)*0.5)] * 1000 / 1000,
        $shiftTimes[(int)(count($shiftTimes)*0.5)] * 1000 / 1000);
}

// --- Test 2: Double fromBinary (the redundant parse) ---
echo "\n--- Double fromBinary (RakLib thread + main thread re-parse) ---\n";

// Build a realistic cross-thread buffer: chr(flags) . identifier . chr(flags) . encapsulatedBinary
function buildCrossThreadBuffer(string $encapsulatedBinary): string {
    $id = "192.168.1.100:19132";
    return chr(\raklib\RakLib::PACKET_ENCAPSULATED) . chr(strlen($id)) . $id . chr(0) . $encapsulatedBinary;
}

function buildEncapsulatedBinary(
    int $reliability, bool $hasSplit, string $payload,
    int $messageIndex = 0, int $orderIndex = 0, int $orderChannel = 0
): string {
    $flags = ($reliability << 5) | ($hasSplit ? 0x10 : 0);
    $header = chr($flags);
    $header .= Binary::writeShort(strlen($payload) * 8);
    if ($reliability > PacketReliability::UNRELIABLE) {
        if ($reliability >= PacketReliability::RELIABLE && $reliability !== PacketReliability::UNRELIABLE_WITH_ACK_RECEIPT) {
            $header .= Binary::writeLTriad($messageIndex);
        }
        if ($reliability <= PacketReliability::RELIABLE_SEQUENCED && $reliability !== PacketReliability::RELIABLE) {
            $header .= Binary::writeLTriad($orderIndex);
            $header .= chr($orderChannel);
        }
    }
    return $header . $payload;
}

$gamePayload = str_repeat("\x06", 256); // simulates a batch packet
$encapsBin = buildEncapsulatedBinary(PacketReliability::RELIABLE_ORDERED, false, $gamePayload, 42, 7, 0);
$crossThreadBuf = buildCrossThreadBuffer($encapsBin);

// Warmup
for ($i = 0; $i < 1000; $i++) {
    // RakLib thread: fromBinary(false) — decode from datagram
    $offset = 0;
    EncapsulatedPacket::fromBinary($encapsBin, false, $offset);
    // Main thread: fromBinary(true) — decode from cross-thread buffer
    $offset2 = 0;
    EncapsulatedPacket::fromBinary($encapsBin, true, $offset2);
}

$doubleParseTimes = [];
for ($r = 0; $r < $rounds; $r++) {
    $t = hrtime(true);
    for ($i = 0; $i < 10000; $i++) {
        $offset = 0;
        EncapsulatedPacket::fromBinary($encapsBin, false, $offset);
        $offset2 = 0;
        EncapsulatedPacket::fromBinary($encapsBin, true, $offset2);
    }
    $doubleParseTimes[] = (hrtime(true) - $t) / 1e3;
}
sort($doubleParseTimes);
$median = $doubleParseTimes[(int)(count($doubleParseTimes) * 0.5)];
printf("  Double fromBinary (false+true):  %7.1f ns/op\n", $median * 1000 / 10000);

// --- Test 3: ServerHandler::handlePacket() — main-thread event drain ---
echo "\n--- ServerHandler::handlePacket() (main-thread event drain) ---\n";

// This simulates the main thread reading from the cross-thread queue and
// re-parsing the encapsulated packet + extracting the game payload
$drainTimes = [];
for ($r = 0; $r < $rounds; $r++) {
    $t = hrtime(true);
    for ($i = 0; $i < 10000; $i++) {
        // Simulate ServerHandler::handlePacket parsing
        $packet = $crossThreadBuf;
        $id = ord($packet[0]);
        $offset = 1;
        // PACKET_ENCAPSULATED
        $len = ord($packet[$offset++]);
        $identifier = substr($packet, $offset, $len);
        $offset += $len;
        $flags = ord($packet[$offset++]);
        $buffer = substr($packet, $offset);
        $ep = EncapsulatedPacket::fromBinary($buffer, true);
    }
    $drainTimes[] = (hrtime(true) - $t) / 1e3;
}
sort($drainTimes);
$median = $drainTimes[(int)(count($drainTimes) * 0.5)];
printf("  Simulated handlePacket:  %7.1f ns/op  (%7.1f µs/op)\n",
    $median * 1000 / 10000, $median / 10000);

// --- Test 4: Batch decompression + inner-packet loop (handleInbound) ---
echo "\n--- Batch decompression + inner-packet loop (handleInbound) ---\n";

// Build a realistic batch payload: zlib-compressed buffer containing
// multiple length-prefixed inner packets
$innerPackets = [];
for ($i = 0; $i < 5; $i++) {
    $innerPackets[] = pack('N', 64) . str_repeat(chr($i), 64);
}
$batchInner = implode('', $innerPackets);
$batchCompressed = zlib_encode($batchInner, ZLIB_ENCODING_DEFLATE, 7);
// Wire format: 0xfe marker + 0x06 (BATCH_PACKET id) + [4-byte compressed size] + compressed data
$batchFrame = chr(0xfe) . chr(0x06) . pack('N', strlen($batchCompressed)) . $batchCompressed;

echo "  Batch frame size: " . strlen($batchFrame) . " bytes (5 inner packets)\n";

// Warmup
for ($i = 0; $i < 1000; $i++) {
    $packetId = ord($batchFrame[0]);
    if ($packetId === 0xfe) {
        $packetId = ord($batchFrame[1]);
        $buf = substr($batchFrame, 1);
    }
    $batch = new \pocketmine\protocol\BatchPacket();
    $batch->setBuffer($buf, 1);
    $batch->decode();
    $payload = zlib_decode($batch->payload, 64 * 1024 * 1024);
    if ($payload === false) throw new \RuntimeException('zlib_decode failed');
    $offset2 = 0;
    $len2 = strlen($payload);
    while ($offset2 + 4 <= $len2) {
        $innerLen = Binary::readInt(substr($payload, $offset2, 4));
        $offset2 += 4;
        if ($innerLen <= 0 || $offset2 + $innerLen > $len2) break;
        $innerBuf = substr($payload, $offset2, $innerLen);
        $offset2 += $innerLen;
    }
}

$batchTimes = [];
for ($r = 0; $r < $rounds; $r++) {
    $t = hrtime(true);
    for ($i = 0; $i < 10000; $i++) {
        $packetId = ord($batchFrame[0]);
        if ($packetId === 0xfe) {
            $packetId = ord($batchFrame[1]);
            $buf = substr($batchFrame, 1);
        }
        $batch = new \pocketmine\protocol\BatchPacket();
        $batch->setBuffer($buf, 1);
        $batch->decode();
        $payload = zlib_decode($batch->payload, 64 * 1024 * 1024);
        if ($payload === false) throw new \RuntimeException('zlib_decode failed');
        $offset2 = 0;
        $len2 = strlen($payload);
        while ($offset2 + 4 <= $len2) {
            $innerLen = Binary::readInt(substr($payload, $offset2, 4));
            $offset2 += 4;
            if ($innerLen <= 0 || $offset2 + $innerLen > $len2) break;
            $innerBuf = substr($payload, $offset2, $innerLen);
            $offset2 += $innerLen;
        }
    }
    $batchTimes[] = (hrtime(true) - $t) / 1e3;
}
sort($batchTimes);
$median = $batchTimes[(int)(count($batchTimes) * 0.5)];
printf("  5-packet batch decode:  %7.1f µs/op  (%.1f µs/pkt)\n",
    $median / 10000, $median / 10000 / 5);

// --- Test 5: Single small packet (no batch) ---
echo "\n--- Single small packet (no batch, 64B) ---\n";

$smallFrame = chr(0xfe) . chr(0x15) . str_repeat("\x42", 64); // 0xfe + some packet id + 64 bytes

$smallTimes = [];
for ($r = 0; $r < $rounds; $r++) {
    $t = hrtime(true);
    for ($i = 0; $i < 10000; $i++) {
        $packetId = ord($smallFrame[0]);
        if ($packetId === 0xfe) {
            $packetId = ord($smallFrame[1]);
            $buf = substr($smallFrame, 1);
        }
        // Not a batch, go to handleGamePacket directly
        $innerBuf = $buf;
    }
    $smallTimes[] = (hrtime(true) - $t) / 1e3;
}
sort($smallTimes);
$median = $smallTimes[(int)(count($smallTimes) * 0.5)];
printf("  Single frame strip:  %7.1f ns/op\n", $median * 1000 / 10000);

// --- Test 6: Outbound — zlib_encode for typical batch sizes ---
echo "\n--- Outbound: zlib_encode (batch + chunk) ---\n";

$smallBatch = str_repeat("\x42", 128);   // small batch (128B)
$medBatch   = str_repeat("\x42", 512);   // medium batch (512B, just above BATCH_THRESHOLD)
$chunkData  = str_repeat("\x42", 81920);  // full chunk (~80KB)

$encodeCases = [
    'small_batch_128B' => $smallBatch,
    'med_batch_512B'   => $medBatch,
    'chunk_80KB'       => $chunkData,
];

// Warmup
foreach ($encodeCases as $data) {
    $inner = pack('N', strlen($data)) . $data;
    zlib_encode($inner, ZLIB_ENCODING_DEFLATE, 7);
}

foreach ($encodeCases as $name => $data) {
    $times = [];
    for ($r = 0; $r < $rounds; $r++) {
        $t = hrtime(true);
        for ($i = 0; $i < 1000; $i++) {
            $inner = pack('N', strlen($data)) . $data;
            $compressed = zlib_encode($inner, ZLIB_ENCODING_DEFLATE, 7);
        }
        $times[] = (hrtime(true) - $t) / 1e3;
    }
    sort($times);
    $median = $times[(int)(count($times) * 0.5)];
    printf("  %-20s  %7.1f µs/op\n", $name, $median / 1000);
}

// --- Test 7: BatchPacket encode (the wrapper) ---
echo "\n--- BatchPacket encode overhead ---\n";

for ($i = 0; $i < 1000; $i++) {
    $bp = new \pocketmine\protocol\BatchPacket();
    $bp->payload = $batchCompressed;
    $bp->encode();
}

$bpTimes = [];
for ($r = 0; $r < $rounds; $r++) {
    $t = hrtime(true);
    for ($i = 0; $i < 10000; $i++) {
        $bp = new \pocketmine\protocol\BatchPacket();
        $bp->payload = $batchCompressed;
        $bp->encode();
    }
    $bpTimes[] = (hrtime(true) - $t) / 1e3;
}
sort($bpTimes);
$median = $bpTimes[(int)(count($bpTimes) * 0.5)];
printf("  BatchPacket encode:  %7.1f ns/op\n", $median * 1000 / 10000);

// --- Test 8: Full outbound path simulation ---
echo "\n--- Full outbound path: encode + zlib + BatchPacket ---\n";

// Simulate what flushOutbound does for a medium packet
$medGamePacket = str_repeat("\x42", 256);

$outboundTimes = [];
for ($r = 0; $r < $rounds; $r++) {
    $t = hrtime(true);
    for ($i = 0; $i < 10000; $i++) {
        // Simulate: packet->encode() + zlib + batch wrap + sendGameFrame
        $buffer = $medGamePacket; // assume already encoded
        $inner = pack('N', strlen($buffer)) . $buffer;
        $compressed = zlib_encode($inner, ZLIB_ENCODING_DEFLATE, 7);
        $bp = new \pocketmine\protocol\BatchPacket();
        $bp->payload = $compressed;
        $bp->encode();
        $frame = chr(0xfe) . $bp->getBuffer();
    }
    $outboundTimes[] = (hrtime(true) - $t) / 1e3;
}
sort($outboundTimes);
$median = $outboundTimes[(int)(count($outboundTimes) * 0.5)];
printf("  Single 256B packet outbound:  %7.1f µs/op\n", $median / 10000);

// --- Summary ---
echo "\n=== Summary ===\n";
echo "Pipeline stages:\n";
echo "  RakLib thread: UDP recv → datagram decode → streamEncapsulated → ThreadSafeArray push\n";
echo "  Main thread: ThreadSafeArray shift → ServerHandler::handlePacket → fromBinary(true)\n";
echo "              → handleInbound → batch decompress → inner-packet dispatch\n";
echo "  Outbound: packet encode → zlib_encode → BatchPacket → sendGameFrame → ThreadSafeArray push\n";
echo "\nKey question: where does time actually go in the per-tick network budget?\n";

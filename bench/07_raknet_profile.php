#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bench/07_raknet_profile.php
 *
 * Profile RakNet packet parsing/serialization hotspots to find FFI candidates.
 * Simulates realistic traffic: datagrams containing multiple encapsulated
 * packets with varying reliability/split flags.
 *
 * Usage: ./bin/php7/bin/php bench/07_raknet_profile.php [rounds]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use raklib\Binary;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\DATA_PACKET_4;
use raklib\protocol\PacketReliability;

$rounds = (int)($argv[1] ?? 50);

echo "=== RakNet packet parsing profile ===\n";
echo "Rounds: $rounds\n\n";

// --- Build realistic test payloads ---

function buildEncapsulatedBinary(
    int $reliability,
    bool $hasSplit,
    string $payload,
    int $messageIndex = 0,
    int $orderIndex = 0,
    int $orderChannel = 0,
    int $splitCount = 0,
    int $splitID = 0,
    int $splitIndex = 0
): string {
    $flags = ($reliability << 5) | ($hasSplit ? 0x10 : 0);
    $header = chr($flags);
    $header .= Binary::writeShort(strlen($payload) * 8); // length in bits

    if ($reliability > PacketReliability::UNRELIABLE) {
        if ($reliability >= PacketReliability::RELIABLE
            && $reliability !== PacketReliability::UNRELIABLE_WITH_ACK_RECEIPT) {
            $header .= Binary::writeLTriad($messageIndex);
        }
        if ($reliability <= PacketReliability::RELIABLE_SEQUENCED
            && $reliability !== PacketReliability::RELIABLE) {
            $header .= Binary::writeLTriad($orderIndex);
            $header .= chr($orderChannel);
        }
    }
    if ($hasSplit) {
        $header .= Binary::writeInt($splitCount);
        $header .= Binary::writeShort($splitID);
        $header .= Binary::writeInt($splitIndex);
    }
    return $header . $payload;
}

// Realistic payload sizes
$smallPayload = str_repeat("\x42", 64);
$medPayload   = str_repeat("\x42", 256);
$bigPayload   = str_repeat("\x42", 1024);

function buildDatagram(array $encapsulateds): string {
    $buf = chr(0x84); // DATA_PACKET_4 ID
    $buf .= Binary::writeLTriad(0); // seqNumber = 0
    foreach ($encapsulateds as $bin) {
        $buf .= $bin;
    }
    return $buf;
}

// --- Test 1: EncapsulatedPacket::fromBinary() throughput ---
echo "--- EncapsulatedPacket::fromBinary() ---\n";

$testCases = [
    'unreliable_small'  => buildEncapsulatedBinary(PacketReliability::UNRELIABLE, false, $smallPayload),
    'reliable_small'    => buildEncapsulatedBinary(PacketReliability::RELIABLE, false, $smallPayload, 42),
    'ordered_small'     => buildEncapsulatedBinary(PacketReliability::RELIABLE_ORDERED, false, $smallPayload, 42, 7, 0),
    'split_small'       => buildEncapsulatedBinary(PacketReliability::RELIABLE_ORDERED, true, $smallPayload, 42, 7, 0, 4, 100, 0),
    'reliable_med'      => buildEncapsulatedBinary(PacketReliability::RELIABLE, false, $medPayload, 100),
    'ordered_med'       => buildEncapsulatedBinary(PacketReliability::RELIABLE_ORDERED, false, $medPayload, 100, 15, 1),
    'reliable_big'      => buildEncapsulatedBinary(PacketReliability::RELIABLE, false, $bigPayload, 200),
    'ordered_big'       => buildEncapsulatedBinary(PacketReliability::RELIABLE_ORDERED, false, $bigPayload, 200, 30, 2),
    'split_big'         => buildEncapsulatedBinary(PacketReliability::RELIABLE_ORDERED, true, $bigPayload, 200, 30, 2, 8, 200, 3),
];

// Warmup
for ($i = 0; $i < 1000; $i++) {
    foreach ($testCases as $bin) {
        $offset = 0;
        EncapsulatedPacket::fromBinary($bin, false, $offset);
    }
}

foreach ($testCases as $name => $bin) {
    $times = [];
    for ($r = 0; $r < $rounds; $r++) {
        $t = hrtime(true);
        for ($i = 0; $i < 1000; $i++) {
            $offset = 0;
            EncapsulatedPacket::fromBinary($bin, false, $offset);
        }
        $times[] = (hrtime(true) - $t) / 1e3;
    }
    sort($times);
    $median = $times[(int)(count($times) * 0.5)];
    printf("  %-20s  median: %7.1f µs/op  (min: %.1f, max: %.1f)\n",
        $name, $median / 1000, $times[0] / 1000, end($times) / 1000);
}

// --- Test 2: EncapsulatedPacket::toBinary() throughput ---
echo "\n--- EncapsulatedPacket::toBinary() ---\n";

$epCases = [
    'unreliable_small' => function() use ($smallPayload) {
        $pk = new EncapsulatedPacket();
        $pk->reliability = PacketReliability::UNRELIABLE;
        $pk->buffer = $smallPayload;
        return $pk;
    },
    'reliable_small' => function() use ($smallPayload) {
        $pk = new EncapsulatedPacket();
        $pk->reliability = PacketReliability::RELIABLE;
        $pk->messageIndex = 42;
        $pk->buffer = $smallPayload;
        return $pk;
    },
    'ordered_small' => function() use ($smallPayload) {
        $pk = new EncapsulatedPacket();
        $pk->reliability = PacketReliability::RELIABLE_ORDERED;
        $pk->messageIndex = 42;
        $pk->orderIndex = 7;
        $pk->orderChannel = 0;
        $pk->buffer = $smallPayload;
        return $pk;
    },
    'reliable_med' => function() use ($medPayload) {
        $pk = new EncapsulatedPacket();
        $pk->reliability = PacketReliability::RELIABLE;
        $pk->messageIndex = 100;
        $pk->buffer = $medPayload;
        return $pk;
    },
    'ordered_med' => function() use ($medPayload) {
        $pk = new EncapsulatedPacket();
        $pk->reliability = PacketReliability::RELIABLE_ORDERED;
        $pk->messageIndex = 100;
        $pk->orderIndex = 15;
        $pk->orderChannel = 1;
        $pk->buffer = $medPayload;
        return $pk;
    },
    'ordered_big' => function() use ($bigPayload) {
        $pk = new EncapsulatedPacket();
        $pk->reliability = PacketReliability::RELIABLE_ORDERED;
        $pk->messageIndex = 200;
        $pk->orderIndex = 30;
        $pk->orderChannel = 2;
        $pk->buffer = $bigPayload;
        return $pk;
    },
];

// Warmup
for ($i = 0; $i < 1000; $i++) {
    foreach ($epCases as $fn) {
        $pk = $fn();
        $pk->toBinary();
    }
}

foreach ($epCases as $name => $fn) {
    $times = [];
    for ($r = 0; $r < $rounds; $r++) {
        $t = hrtime(true);
        for ($i = 0; $i < 1000; $i++) {
            $pk = $fn();
            $pk->toBinary();
        }
        $times[] = (hrtime(true) - $t) / 1e3;
    }
    sort($times);
    $median = $times[(int)(count($times) * 0.5)];
    printf("  %-20s  median: %7.1f µs/op  (min: %.1f, max: %.1f)\n",
        $name, $median / 1000, $times[0] / 1000, end($times) / 1000);
}

// --- Test 3: Binary::readLTriad / writeLTriad ---
echo "\n--- Binary::readLTriad / writeLTriad ---\n";

$triadData = Binary::writeLTriad(0xABCDEF & 0xFFFFFF);

for ($i = 0; $i < 1000; $i++) {
    Binary::readLTriad($triadData);
    Binary::writeLTriad(0xABCDEF & 0xFFFFFF);
}

$triadReadTimes = [];
$triadWriteTimes = [];
for ($r = 0; $r < $rounds; $r++) {
    $t = hrtime(true);
    for ($i = 0; $i < 100000; $i++) {
        Binary::readLTriad($triadData);
    }
    $triadReadTimes[] = (hrtime(true) - $t) / 1e3;

    $t = hrtime(true);
    for ($i = 0; $i < 100000; $i++) {
        Binary::writeLTriad(0xABCDEF & 0xFFFFFF);
    }
    $triadWriteTimes[] = (hrtime(true) - $t) / 1e3;
}
sort($triadReadTimes); sort($triadWriteTimes);
printf("  readLTriad:   %7.2f ns/op  (median of %d rounds × 100k)\n",
    $triadReadTimes[(int)(count($triadReadTimes) * 0.5)] * 1000 / 100000, $rounds);
printf("  writeLTriad:  %7.2f ns/op  (median of %d rounds × 100k)\n",
    $triadWriteTimes[(int)(count($triadWriteTimes) * 0.5)] * 1000 / 100000, $rounds);

// --- Test 4: Full datagram decode ---
echo "\n--- DataPacket::decode() (full datagram) ---\n";

$encaps = [
    buildEncapsulatedBinary(PacketReliability::RELIABLE_ORDERED, false, $medPayload, 1, 0, 0),
    buildEncapsulatedBinary(PacketReliability::RELIABLE_ORDERED, false, $medPayload, 2, 1, 0),
    buildEncapsulatedBinary(PacketReliability::RELIABLE_ORDERED, false, $smallPayload, 3, 2, 0),
    buildEncapsulatedBinary(PacketReliability::RELIABLE, false, $bigPayload, 4),
    buildEncapsulatedBinary(PacketReliability::RELIABLE_ORDERED, false, $medPayload, 5, 3, 1),
    buildEncapsulatedBinary(PacketReliability::UNRELIABLE, false, $smallPayload),
    buildEncapsulatedBinary(PacketReliability::RELIABLE_ORDERED, false, $smallPayload, 6, 4, 0),
    buildEncapsulatedBinary(PacketReliability::RELIABLE, false, $medPayload, 7),
    buildEncapsulatedBinary(PacketReliability::RELIABLE_ORDERED, false, $smallPayload, 8, 5, 0),
    buildEncapsulatedBinary(PacketReliability::RELIABLE_ORDERED, false, $medPayload, 9, 6, 1),
];
$datagram = buildDatagram($encaps);

// Warmup
for ($i = 0; $i < 1000; $i++) {
    $dp = new DATA_PACKET_4();
    $dp->buffer = $datagram;
    $dp->decode();
}

$dgTimes = [];
for ($r = 0; $r < $rounds; $r++) {
    $t = hrtime(true);
    for ($i = 0; $i < 10000; $i++) {
        $dp = new DATA_PACKET_4();
        $dp->buffer = $datagram;
        $dp->decode();
    }
    $dgTimes[] = (hrtime(true) - $t) / 1e3;
}
sort($dgTimes);
$median = $dgTimes[(int)(count($dgTimes) * 0.5)];
printf("  10-packet datagram decode:  %7.1f µs/op  (%.1f ns/pkt)\n",
    $median / 10000, ($median * 1000) / 10000);

// --- Test 5: Binary::readInt / readShort / readSignedShort ---
echo "\n--- Binary::readInt / readShort / readSignedShort ---\n";

$intData = Binary::writeInt(0xDEADBEEF);
$shortData = Binary::writeShort(0xBEEF);

for ($i = 0; $i < 1000; $i++) {
    Binary::readInt($intData);
    Binary::readShort($shortData);
    Binary::readSignedShort($shortData);
}

$intTimes = [];
$shortTimes = [];
$sshortTimes = [];
for ($r = 0; $r < $rounds; $r++) {
    $t = hrtime(true);
    for ($i = 0; $i < 100000; $i++) { Binary::readInt($intData); }
    $intTimes[] = (hrtime(true) - $t) / 1e3;

    $t = hrtime(true);
    for ($i = 0; $i < 100000; $i++) { Binary::readShort($shortData); }
    $shortTimes[] = (hrtime(true) - $t) / 1e3;

    $t = hrtime(true);
    for ($i = 0; $i < 100000; $i++) { Binary::readSignedShort($shortData); }
    $sshortTimes[] = (hrtime(true) - $t) / 1e3;
}
sort($intTimes); sort($shortTimes); sort($sshortTimes);
printf("  readInt:          %7.2f ns/op\n",
    $intTimes[(int)(count($intTimes)*0.5)] * 1000 / 100000);
printf("  readShort:        %7.2f ns/op\n",
    $shortTimes[(int)(count($shortTimes)*0.5)] * 1000 / 100000);
printf("  readSignedShort:  %7.2f ns/op\n",
    $sshortTimes[(int)(count($sshortTimes)*0.5)] * 1000 / 100000);

// --- Test 6: AcknowledgePacket decode ---
echo "\n--- AcknowledgePacket (ACK/NACK) ---\n";

$ackSeqs = range(100, 131);
$ack = new \raklib\protocol\ACK();
$ack->packets = $ackSeqs;
$ack->encode();
$ackBinary = $ack->buffer;

for ($i = 0; $i < 1000; $i++) {
    $a = new \raklib\protocol\ACK();
    $a->buffer = $ackBinary;
    $a->decode();
}

$ackTimes = [];
for ($r = 0; $r < $rounds; $r++) {
    $t = hrtime(true);
    for ($i = 0; $i < 10000; $i++) {
        $a = new \raklib\protocol\ACK();
        $a->buffer = $ackBinary;
        $a->decode();
    }
    $ackTimes[] = (hrtime(true) - $t) / 1e3;
}
sort($ackTimes);
$median = $ackTimes[(int)(count($ackTimes) * 0.5)];
printf("  ACK decode (32 seqs):  %7.1f µs/op\n", $median / 10000);

// --- Test 7: Full round-trip ---
echo "\n--- Full round-trip: fromBinary → toBinary (internal) ---\n";

$roundTripCases = [
    'reliable_ordered_med' => function() use ($medPayload) {
        $bin = buildEncapsulatedBinary(PacketReliability::RELIABLE_ORDERED, false, $medPayload, 42, 7, 0);
        $offset = 0;
        $pk = EncapsulatedPacket::fromBinary($bin, false, $offset);
        return $pk;
    },
    'reliable_ordered_big_split' => function() use ($bigPayload) {
        $bin = buildEncapsulatedBinary(PacketReliability::RELIABLE_ORDERED, true, $bigPayload, 42, 7, 0, 4, 100, 0);
        $offset = 0;
        $pk = EncapsulatedPacket::fromBinary($bin, false, $offset);
        return $pk;
    },
];

for ($i = 0; $i < 1000; $i++) {
    foreach ($roundTripCases as $fn) {
        $pk = $fn();
        $pk->toBinary(true);
    }
}

foreach ($roundTripCases as $name => $fn) {
    $times = [];
    for ($r = 0; $r < $rounds; $r++) {
        $t = hrtime(true);
        for ($i = 0; $i < 10000; $i++) {
            $pk = $fn();
            $pk->toBinary(true);
        }
        $times[] = (hrtime(true) - $t) / 1e3;
    }
    sort($times);
    $median = $times[(int)(count($times) * 0.5)];
    printf("  %-30s  %7.1f µs/op\n", $name, $median / 10000);
}

echo "\n=== Summary ===\n";
echo "Focus areas for FFI:\n";
echo "1. EncapsulatedPacket::fromBinary — batched header parse (replaces 5-8 substr+unpack calls)\n";
echo "2. EncapsulatedPacket::toBinary — batched header serialize\n";
echo "3. Full datagram decode — batched encapsulated packet loop\n";
echo "4. Binary::readLTriad — most-called primitive, but ns-level; batch into fromBinary instead\n";

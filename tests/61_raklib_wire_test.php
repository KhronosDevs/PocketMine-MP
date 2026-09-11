<?php
declare(strict_types=1);
require __DIR__ . '/../autoload.php';

use raklib\Binary;
use raklib\protocol\AcknowledgePacket;
use raklib\protocol\DATA_PACKET_4;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\NACK;
use raklib\protocol\PacketReliability;

require __DIR__ . '/helpers.php';

/**
 * The 2026-09 wire-pass rewrote the encapsulated header parse (and its
 * Binary helpers) from substr+unpack per field to in-place ord() math.
 * These tests pin byte-level parity of the new readers against the
 * reference pack()/unpack() implementations and lock the exact wire
 * format with hand-computed golden vectors, so any future change that
 * alters a single byte on the wire fails here.
 */

test('readLTriadAt matches readLTriad(substr(...)) for all bit patterns', function (): void {
	for ($i = 0; $i < 2000; ++$i) {
		$v = mt_rand(0, 0xFFFFFF);
		$s = Binary::writeLTriad($v);
		same($v, Binary::readLTriadAt($s, 0), "roundtrip $v");
		same(Binary::readLTriad($s), Binary::readLTriadAt($s, 0), "parity $v");
	}
	// Boundary values.
	foreach ([0, 1, 0xFF, 0x100, 0xFFFF, 0x10000, 0xFFFFFF] as $v) {
		$s = Binary::writeLTriad($v);
		same($v, Binary::readLTriadAt($s, 0), "boundary " . dechex($v));
	}
});

test('writeLTriad matches the reference pack("V") bytes', function (): void {
	foreach ([0, 1, 0x123456, 0xFEDCBA, 0xFFFFFF, 0x010203] as $v) {
		$expected = substr(pack("V", $v), 0, -1);
		same($expected, Binary::writeLTriad($v), "writeLTriad " . dechex($v));
	}
});

test('readUShortAt / readUIntAt match the reference unpack readers', function (): void {
	for ($i = 0; $i < 1000; ++$i) {
		$s16 = Binary::writeShort(mt_rand(0, 0xFFFF));
		same(Binary::readShort($s16), Binary::readUShortAt($s16, 0), "ushort parity");

		// unsigned 31-bit range only: readInt() sign-extends, readUIntAt()
		// does not (the fields it serves — split counts/indexes and internal
		// frame lengths — are physically far below 2^31).
		$v32 = mt_rand(0, 0x7FFFFFFF);
		$s32 = Binary::writeInt($v32);
		same($v32, Binary::readUIntAt($s32, 0), "uint roundtrip");

		// In-buffer offset handling: 3 padding bytes then the fields.
		$buf = "xyz" . $s16 . $s32;
		same(Binary::readShort(substr($buf, 3, 2)), Binary::readUShortAt($buf, 3), "ushort at offset");
		same($v32, Binary::readUIntAt($buf, 5), "uint at offset");
	}
});

// Golden vectors, hand-computed from the RakNet encapsulated header format.
test('golden wire bytes: RELIABLE_ORDERED header roundtrip', function (): void {
	// flags: (3 << 5) = 0x60 | no split
	// len: strlen("abc") << 3 = 24 = 0x0018 (BE)
	// messageIndex 5 (LE triad), orderIndex 7 (LE triad), orderChannel 1
	$wire = "\x60\x00\x18\x05\x00\x00\x07\x00\x00\x01abc";
	[$pk, $offset] = EncapsulatedPacket::parseAt($wire, 0);
	same(PacketReliability::RELIABLE_ORDERED, $pk->reliability);
	same(false, $pk->hasSplit);
	same(5, $pk->messageIndex);
	same(7, $pk->orderIndex);
	same(1, $pk->orderChannel);
	same("abc", $pk->buffer);
	same(13, $offset, "offset just past the payload");
	same(null, $pk->identifierACK);
	same(null, $pk->splitCount);

	// And the packet re-encodes to the exact same bytes.
	same($wire, $pk->toBinary());
});

test('golden wire bytes: RELIABLE with split fields', function (): void {
	// flags: (2 << 5) | SPLIT_FLAG = 0x50
	// len: 1 << 3 = 0x0008; messageIndex 1; splitCount 3; splitID 9; splitIndex 2
	$wire = "\x50\x00\x08\x01\x00\x00\x00\x00\x00\x03\x00\x09\x00\x00\x00\x02X";
	[$pk, $offset] = EncapsulatedPacket::parseAt($wire, 0);
	same(PacketReliability::RELIABLE, $pk->reliability);
	same(true, $pk->hasSplit);
	same(1, $pk->messageIndex);
	same(3, $pk->splitCount);
	same(9, $pk->splitID);
	same(2, $pk->splitIndex);
	same("X", $pk->buffer);
	same(17, $offset);
	same(null, $pk->orderIndex, "RELIABLE has no order fields");
	same($wire, $pk->toBinary());
});

test('golden wire bytes: UNRELIABLE and UNRELIABLE_SEQUENCED header sizes', function (): void {
	// UNRELIABLE: flags 0x00, len 2<<3, no index fields at all.
	$wire = "\x00\x00\x10hi";
	[$pk, $offset] = EncapsulatedPacket::parseAt($wire, 0);
	same(PacketReliability::UNRELIABLE, $pk->reliability);
	same(null, $pk->messageIndex);
	same(null, $pk->orderIndex);
	same("hi", $pk->buffer);
	same(5, $offset);
	same($wire, $pk->toBinary());

	// UNRELIABLE_SEQUENCED (1): order fields present, messageIndex absent
	// (reliability < RELIABLE). Header = 1 + 2 + 4 = 7 bytes.
	$wire = "\x20\x00\x08" . "\x09\x00\x00" . "\x02" . "z";
	[$pk, $offset] = EncapsulatedPacket::parseAt($wire, 0);
	same(PacketReliability::UNRELIABLE_SEQUENCED, $pk->reliability);
	same(null, $pk->messageIndex);
	same(9, $pk->orderIndex);
	same(2, $pk->orderChannel);
	same("z", $pk->buffer);
	same(8, $offset);
	same($wire, $pk->toBinary());
});

test('golden wire bytes: internal frame variant reads length + identifierACK', function (): void {
	// Main-thread frames (streamEncapsulated) carry internal=true: the
	// header is flags(1) + plain byte length(4) + identifierACK(4) — the
	// 2-byte bit-length field only exists on the external wire format.
	$wire = "\x60" . Binary::writeInt(3) . Binary::writeInt(42)
		. "\x05\x00\x00\x07\x00\x00\x01abc";
	[$pk, $offset] = EncapsulatedPacket::parseAt($wire, 0, true);
	same(PacketReliability::RELIABLE_ORDERED, $pk->reliability);
	same(42, $pk->identifierACK);
	same(5, $pk->messageIndex);
	same(7, $pk->orderIndex);
	same(1, $pk->orderChannel);
	same("abc", $pk->buffer);
	same(strlen($wire), $offset);
	same($wire, $pk->toBinary(true));
});

test('parseAt truncation tolerance matches legacy behaviour', function (): void {
	// Empty buffer: no crash, empty packet, loop-terminating offset.
	[$pk, $offset] = EncapsulatedPacket::parseAt("", 0);
	same("", $pk->buffer);
	same(0, $offset);

	// Header cut mid-way (RELIABLE_ORDERED needs 10 header bytes, got 4):
	// legacy null-coerced the missing fields into a zero-length packet.
	// New code delivers the same empty packet without warnings.
	[$pk, $offset] = EncapsulatedPacket::parseAt("\x60\x00\x18\x05", 0);
	same("", $pk->buffer, "truncated header collapses to empty payload");

	// Overlong length field: substr clamps to the bytes actually present —
	// IDENTICAL to legacy. (0xffff << 3 = 8192; len = ceil(8192/8) = 8192;
	// substr() returns the 2-byte remainder and offset = 3 + 8192. The old
	// code did exactly this because both paths feed the same substr call.)
	$wire = "\x00\xff\xff" . "hi";
	[$pk, $offset] = EncapsulatedPacket::parseAt($wire, 0);
	same("hi", $pk->buffer);
	same(3 + 8192, $offset, "offset math identical to legacy substr clamping");

	// Mid-buffer start offset (DataPacket::decode walks packets this way).
	$wire = "\x00\x00\x08A" . "\x00\x00\x08B";
	[$pk1, $off1] = EncapsulatedPacket::parseAt($wire, 0);
	[$pk2, $off2] = EncapsulatedPacket::parseAt($wire, $off1);
	same("A", $pk1->buffer);
	same("B", $pk2->buffer);
	same(strlen($wire), $off2);
});

test('DataPacket decode reassembles a multi-packet datagram byte-exactly', function (): void {
	$pk1 = new EncapsulatedPacket();
	$pk1->reliability = PacketReliability::RELIABLE_ORDERED;
	$pk1->messageIndex = 1;
	$pk1->orderIndex = 0;
	$pk1->orderChannel = 0;
	$pk1->buffer = "hello";

	$pk2 = new EncapsulatedPacket();
	$pk2->reliability = PacketReliability::UNRELIABLE;
	$pk2->buffer = "world";

	$pk3 = new EncapsulatedPacket();
	$pk3->reliability = PacketReliability::RELIABLE;
	$pk3->messageIndex = 2;
	$pk3->buffer = "!";

	$datagram = new DATA_PACKET_4();
	$datagram->seqNumber = 77;
	$datagram->packets = [$pk1->toBinary(), $pk2->toBinary(), $pk3->toBinary()];
	$datagram->encode();

	$decoded = new DATA_PACKET_4();
	$decoded->buffer = $datagram->buffer;
	$decoded->decode();

	same(77, $decoded->seqNumber);
	same(3, count($decoded->packets));
	same("hello", $decoded->packets[0]->buffer);
	same(1, $decoded->packets[0]->messageIndex);
	same("world", $decoded->packets[1]->buffer);
	same("!", $decoded->packets[2]->buffer);

	// Trailing garbage (a truncated header after valid packets) must not
	// hang the decode loop nor emit warnings: the valid packets decode,
	// the malformed tail collapses to an empty packet and stops the loop.
	$garbage = $datagram->buffer . "\x60\x00";
	$decoded2 = new DATA_PACKET_4();
	$decoded2->buffer = $garbage;
	$decoded2->decode();
	same(3, count($decoded2->packets), "valid packets survive a malformed tail");
});

test('ACK/NACK encode-decode roundtrip and malformed-record tolerance', function (): void {
	$ack = new NACK();
	$ack->packets = [7, 1, 3, 2]; // encode run-length-compresses 1..3 + 7
	$ack->encode();
	$decoded = new NACK();
	$decoded->buffer = $ack->buffer;
	$decoded->decode();
	same([1, 2, 3, 7], $decoded->packets, "single and range records re-expand");

	// Malformed range record (end < start): the legacy loop appended
	// nothing for it (for-loop condition never held); the new explicit
	// guard keeps that outcome while dropping the null-id garbage that
	// truncated reads used to append.
	$nack = new NACK();
	$nack->encode(); // header only
	$wire = $nack->buffer
		. Binary::writeShort(1) // one record
		. "\x00"               // range record
		. Binary::writeLTriad(5)
		. Binary::writeLTriad(2); // end < start
	$decodedNack = new NACK();
	$decodedNack->buffer = $wire;
	$decodedNack->decode();
	same([], $decodedNack->packets, "end<start record contributes no ids");
});

echo runTests() === 0 ? "\nDONE\n" : "\nFAILED\n";
exit(runTests());

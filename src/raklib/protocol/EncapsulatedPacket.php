<?php



/*
 * RakLib network library
 *
 *
 * This project is not affiliated with Jenkins Software LLC nor RakNet.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 */

declare(strict_types=1);

namespace raklib\protocol;



use raklib\Binary;
use function ceil;
use function chr;
use function ord;
use function strlen;
use function substr;

class EncapsulatedPacket{
	const RELIABILITY_SHIFT = 5;
	const RELIABILITY_FLAGS = 0b111 << self::RELIABILITY_SHIFT;

	const SPLIT_FLAG = 0b00010000;

	public int $reliability = 0;
	public bool $hasSplit = false;
	public int $length = 0;
	public ?int $messageIndex = null;
	public ?int $orderIndex = null;
	public ?int $orderChannel = null;
	public ?int $splitCount = null;
	public ?int $splitID = null;
	public ?int $splitIndex = null;
	public string $buffer = "";
	public bool $needACK = false;
	public ?int $identifierACK = null;

	public static function fromBinary(string $binary, bool $internal = false, ?int &$offset = null) : EncapsulatedPacket{
		[$packet, $offset] = self::parseAt($binary, 0, $internal);
		return $packet;
	}

	/**
	 * Parse one encapsulated packet starting at absolute byte $start of a
	 * larger buffer, returning the packet and the offset just past it.
	 *
	 * Reading the whole datagram (or main-thread frame) in place lets a
	 * caller decode many packets without re-substr-ing the remaining buffer
	 * per packet - DataPacket::decode was O(n^2) in packets-per-datagram
	 * because each iteration copied everything left.
	 *
	 * The header fields themselves are now ALSO read in place (ord() math
	 * instead of substr + unpack per field): every encapsulated header
	 * allocated 3-7 small strings just to throw them away, and at 100 Hz ×
	 * sessions × packets-per-datagram that dominated the wire thread.
	 * Behaviour is identical to fromBinary(): same fields, same truncation
	 * tolerance - a header shorter than its declared fields yields an
	 * empty-buffer packet (the old null-coerced reads collapsed to length
	 * 0), and an overlong payload length yields the clamped remainder
	 * exactly like substr() always did.
	 *
	 * @return array{0: EncapsulatedPacket, 1: int} [packet, next offset]
	 */
	public static function parseAt(string $binary, int $start, bool $internal = false) : array{
		if(!isset($binary[$start])){
			// Empty / out-of-range input: same empty-packet shape as the
			// truncated-header path below (the legacy code emitted a warning
			// here; the DataPacket::decode loop never fed us one, but a
			// defensive guard keeps malformed input warning-free).
			return [new EncapsulatedPacket(), strlen($binary)];
		}
		$flags = ord($binary[$start]);
		$reliability = ($flags & self::RELIABILITY_FLAGS) >> self::RELIABILITY_SHIFT;
		$hasSplit = ($flags & self::SPLIT_FLAG) > 0;

		// Exact header size for this flag combination, checked once against
		// the buffer length so every field read below is provably in bounds
		// (no per-field isset traffic, no warnings on malformed input).
		$headerSize = 1 + ($internal ? 8 : 2);
		if($reliability > PacketReliability::UNRELIABLE){
			if($reliability >= PacketReliability::RELIABLE && $reliability !== PacketReliability::UNRELIABLE_WITH_ACK_RECEIPT){
				$headerSize += 3;
			}
			if($reliability <= PacketReliability::RELIABLE_SEQUENCED && $reliability !== PacketReliability::RELIABLE){
				$headerSize += 4;
			}
		}
		if($hasSplit){
			$headerSize += 10;
		}

		if(!isset($binary[$start + $headerSize - 1])){
			// Truncated header: the old code null-coerced every field read
			// and collapsed to a zero-length packet. Deliver the same shape
			// (empty buffer) and park the offset at the end so a decode loop
			// terminates instead of spinning.
			$packet = new EncapsulatedPacket();
			$packet->reliability = $reliability;
			$packet->hasSplit = $hasSplit;
			return [$packet, strlen($binary)];
		}

		$packet = new EncapsulatedPacket();
		$packet->reliability = $reliability;
		$packet->hasSplit = $hasSplit;
		$offset = $start + 1;

		if($internal){
			$length = Binary::readUIntAt($binary, $offset);
			$packet->identifierACK = Binary::readUIntAt($binary, $offset + 4);
			$offset += 8;
		}else{
			$length = (int) ceil(Binary::readUShortAt($binary, $offset) / 8);
			$packet->identifierACK = null;
			$offset += 2;
		}

		if($reliability > PacketReliability::UNRELIABLE){
			if($reliability >= PacketReliability::RELIABLE && $reliability !== PacketReliability::UNRELIABLE_WITH_ACK_RECEIPT){
				$packet->messageIndex = Binary::readLTriadAt($binary, $offset);
				$offset += 3;
			}

			if($reliability <= PacketReliability::RELIABLE_SEQUENCED && $reliability !== PacketReliability::RELIABLE){
				$packet->orderIndex = Binary::readLTriadAt($binary, $offset);
				$packet->orderChannel = ord($binary[$offset + 3]);
				$offset += 4;
			}
		}

		if($hasSplit){
			$packet->splitCount = Binary::readUIntAt($binary, $offset);
			$packet->splitID = Binary::readUShortAt($binary, $offset + 4);
			$packet->splitIndex = Binary::readUIntAt($binary, $offset + 6);
			$offset += 10;
		}

		// The payload still goes through substr() - this is the game data
		// and must be copied anyway. substr() clamps $length to the bytes
		// actually available, preserving the old tolerance for overlong
		// length fields.
		$packet->buffer = substr($binary, $offset, $length);
		$offset += $length;

		return [$packet, $offset];
	}

	public function getTotalLength() : int{
		return 3 + strlen($this->buffer) + ($this->messageIndex !== null ? 3 : 0) + ($this->orderIndex !== null ? 4 : 0) + ($this->hasSplit ? 10 : 0);
	}

	public function toBinary(bool $internal = false) : string{
		return
			chr(($this->reliability << self::RELIABILITY_SHIFT) | ($this->hasSplit ? self::SPLIT_FLAG : 0)) .
			($internal ? Binary::writeInt(strlen($this->buffer)) . Binary::writeInt($this->identifierACK ?? 0) : Binary::writeShort(strlen($this->buffer) << 3)) .
			($this->reliability > PacketReliability::UNRELIABLE ?
				(($this->reliability >= PacketReliability::RELIABLE && $this->reliability !== PacketReliability::UNRELIABLE_WITH_ACK_RECEIPT) ? Binary::writeLTriad($this->messageIndex ?? 0) : "") . //?? 0: null fields encode as 0 like original weak mode
				(($this->reliability <= PacketReliability::RELIABLE_SEQUENCED && $this->reliability !== PacketReliability::RELIABLE) ? Binary::writeLTriad($this->orderIndex ?? 0) . chr($this->orderChannel ?? 0) : "")
				: ""
			) .
			($this->hasSplit ? Binary::writeInt($this->splitCount ?? 0) . Binary::writeShort($this->splitID ?? 0) . Binary::writeInt($this->splitIndex ?? 0) : "")
			. $this->buffer;
	}

	public function __toString() : string{
		return $this->toBinary();
	}
}

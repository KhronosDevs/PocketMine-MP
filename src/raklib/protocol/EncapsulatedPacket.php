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

		$packet = new EncapsulatedPacket();

		$flags = ord($binary[0]);
		$packet->reliability = $reliability = ($flags & self::RELIABILITY_FLAGS) >> self::RELIABILITY_SHIFT;
		$packet->hasSplit = $hasSplit = ($flags & self::SPLIT_FLAG) > 0;
		if($internal){
			$length = Binary::readInt(substr($binary, 1, 4));
			$packet->identifierACK = Binary::readInt(substr($binary, 5, 4));
			$offset = 9;
		}else{
			$length = (int) ceil(Binary::readShort(substr($binary, 1, 2)) / 8);
			$offset = 3;
			$packet->identifierACK = null;
		}

		if($reliability > PacketReliability::UNRELIABLE){
			if($reliability >= PacketReliability::RELIABLE && $reliability !== PacketReliability::UNRELIABLE_WITH_ACK_RECEIPT){
				$packet->messageIndex = Binary::readLTriad(substr($binary, $offset, 3));
				$offset += 3;
			}

			if($reliability <= PacketReliability::RELIABLE_SEQUENCED && $reliability !== PacketReliability::RELIABLE){
				$packet->orderIndex = Binary::readLTriad(substr($binary, $offset, 3));
				$offset += 3;
				$packet->orderChannel = ord($binary[$offset++]);
			}
		}

		if($hasSplit){
			$packet->splitCount = Binary::readInt(substr($binary, $offset, 4));
			$offset += 4;
			$packet->splitID = Binary::readShort(substr($binary, $offset, 2));
			$offset += 2;
			$packet->splitIndex = Binary::readInt(substr($binary, $offset, 4));
			$offset += 4;
		}

		$packet->buffer = substr($binary, $offset, $length);
		$offset += $length;

		return $packet;
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

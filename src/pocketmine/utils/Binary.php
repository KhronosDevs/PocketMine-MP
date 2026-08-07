<?php



/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

/**
 * Various Utilities used around the code
 *
 * NOTE: This file deliberately does NOT use declare(strict_types=1). It is the packet/serialization
 * backbone of the server and receives values of mixed types from every other module; keeping it in
 * weak mode preserves the historical argument-coercion behavior exactly.
 */
namespace pocketmine\utils;

use function bcadd;
use function bccomp;
use function bcdiv;
use function bcmod;
use function bcmul;
use function chr;
use function ord;
use function pack;
use function preg_replace;
use function sprintf;
use function strlen;
use function strrev;
use function strval;
use function substr;
use function unpack;
use const PHP_INT_SIZE;

class Binary{
	const BIG_ENDIAN = 0x00;
	const LITTLE_ENDIAN = 0x01;

	// Entity metadata data-type codes (previously from pocketmine\entity\Entity).
	public const DATA_TYPE_BYTE = 0;
	public const DATA_TYPE_SHORT = 1;
	public const DATA_TYPE_INT = 2;
	public const DATA_TYPE_FLOAT = 3;
	public const DATA_TYPE_STRING = 4;
	public const DATA_TYPE_SLOT = 5;
	public const DATA_TYPE_POS = 6;
	public const DATA_TYPE_LONG = 7;

	private static ?int $endianness = null;

	/**
	 * Detects the platform byte order once (replaces the legacy global ENDIANNESS constant).
	 */
	public static function endianness() : int{
		if(self::$endianness === null){
			self::$endianness = pack("L", 1) === "\x01\x00\x00\x00" ? self::LITTLE_ENDIAN : self::BIG_ENDIAN;
		}
		return self::$endianness;
	}

	/**
	 * Reads a 3-byte big-endian number. Returns 0 on truncated input (see readInt guard).
	 */
	public static function readTriad(string $str) : int{
		if(strlen($str) < 3) return 0;
		return unpack("N", "\x00" . $str)[1];
	}

	/**
	 * Writes a 3-byte big-endian number
	 */
	public static function writeTriad(int $value) : string{
		return substr(pack("N", $value), 1);
	}

	/**
	 * Reads a 3-byte little-endian number. Returns 0 on truncated input (see readInt guard).
	 */
	public static function readLTriad(string $str) : int{
		if(strlen($str) < 3) return 0;
		return unpack("V", $str . "\x00")[1];
	}

	/**
	 * Writes a 3-byte little-endian number
	 */
	public static function writeLTriad(int $value) : string{
		return substr(pack("V", $value), 0, -1);
	}

	/**
	 * Writes a coded metadata string
	 */
	public static function writeMetadata(array $data) : string{
		$m = "";
		foreach($data as $bottom => $d){
			$m .= chr(($d[0] << 5) | ($bottom & 0x1F));
			switch($d[0]){
				case self::DATA_TYPE_BYTE:
					$m .= self::writeByte($d[1]);
					break;
				case self::DATA_TYPE_SHORT:
					$m .= self::writeLShort($d[1]);
					break;
				case self::DATA_TYPE_INT:
					$m .= self::writeLInt($d[1]);
					break;
				case self::DATA_TYPE_FLOAT:
					$m .= self::writeLFloat($d[1]);
					break;
				case self::DATA_TYPE_STRING:
					$m .= self::writeLShort(strlen($d[1])) . $d[1];
					break;
				case self::DATA_TYPE_SLOT:
					$m .= self::writeLShort($d[1][0]);
					$m .= self::writeByte($d[1][1]);
					$m .= self::writeLShort($d[1][2]);
					break;
				case self::DATA_TYPE_POS:
					$m .= self::writeLInt($d[1][0]);
					$m .= self::writeLInt($d[1][1]);
					$m .= self::writeLInt($d[1][2]);
					break;
				case self::DATA_TYPE_LONG:
					$m .= self::writeLLong($d[1]);
					break;
			}
		}
		$m .= "\x7f";

		return $m;
	}

	/**
	 * Reads a metadata coded string
	 */
	public static function readMetadata(string $value, bool $types = false) : array{
		$offset = 0;
		$m = [];
		$b = ord($value[$offset]);
		++$offset;
		while($b !== 127 && isset($value[$offset])){
			$bottom = $b & 0x1F;
			$type = $b >> 5;
			switch($type){
				case self::DATA_TYPE_BYTE:
					$r = self::readByte($value[$offset]);
					++$offset;
					break;
				case self::DATA_TYPE_SHORT:
					$r = self::readLShort(substr($value, $offset, 2));
					$offset += 2;
					break;
				case self::DATA_TYPE_INT:
					$r = self::readLInt(substr($value, $offset, 4));
					$offset += 4;
					break;
				case self::DATA_TYPE_FLOAT:
					$r = self::readLFloat(substr($value, $offset, 4));
					$offset += 4;
					break;
				case self::DATA_TYPE_STRING:
					$len = self::readLShort(substr($value, $offset, 2));
					$offset += 2;
					$r = substr($value, $offset, $len);
					$offset += $len;
					break;
				case self::DATA_TYPE_SLOT:
					$r = [];
					$r[] = self::readLShort(substr($value, $offset, 2));
					$offset += 2;
					$r[] = ord($value[$offset]);
					++$offset;
					$r[] = self::readLShort(substr($value, $offset, 2));
					$offset += 2;
					break;
				case self::DATA_TYPE_POS:
					$r = [];
					for($i = 0; $i < 3; ++$i){
						$r[] = self::readLInt(substr($value, $offset, 4));
						$offset += 4;
					}
					break;
				case self::DATA_TYPE_LONG:
					$r = self::readLLong(substr($value, $offset, 4));
					$offset += 8;
					break;
				default:
					return [];

			}
			if($types === true){
				$m[$bottom] = [$r, $type];
			}else{
				$m[$bottom] = $r;
			}
			$b = ord($value[$offset]);
			++$offset;
		}

		return $m;
	}

	/**
	 * Reads a byte boolean
	 */
	public static function readBool(string $b) : bool{
		return self::readByte($b, false) !== 0;
	}

	/**
	 * Writes a byte boolean
	 */
	public static function writeBool(bool $b) : string{
		return self::writeByte($b ? 1 : 0);
	}

	/**
	 * Reads an unsigned/signed byte
	 */
	public static function readByte(string $c, bool $signed = true) : int{
		$b = ord($c[0]);

		if($signed){
			if(PHP_INT_SIZE === 8){
				return $b << 56 >> 56;
			}else{
				return $b << 24 >> 24;
			}
		}else{
			return $b;
		}
	}

	/**
	 * Writes an unsigned/signed byte
	 */
	public static function writeByte(int $c) : string{
		return chr($c);
	}

	/**
	 * Reads a 16-bit unsigned big-endian number. Returns 0 on truncated input (see readInt guard).
	 */
	public static function readShort(string $str) : int{
		if(strlen($str) < 2) return 0;
		return unpack("n", $str)[1];
	}

	/**
	 * Reads a 16-bit signed big-endian number
	 */
	public static function readSignedShort(string $str) : int{
		if(PHP_INT_SIZE === 8){
			return unpack("n", $str)[1] << 48 >> 48;
		}else{
			return unpack("n", $str)[1] << 16 >> 16;
		}
	}

	/**
	 * Writes a 16-bit signed/unsigned big-endian number
	 */
	public static function writeShort(int $value) : string{
		return pack("n", $value);
	}

	/**
	 * Reads a 16-bit unsigned little-endian number. Returns 0 on truncated input (see readInt guard).
	 */
	public static function readLShort(string $str) : int{
		if(strlen($str) < 2) return 0;
		return unpack("v", $str)[1];
	}

	/**
	 * Reads a 16-bit signed little-endian number
	 */
	public static function readSignedLShort(string $str) : int{
		if(PHP_INT_SIZE === 8){
			return unpack("v", $str)[1] << 48 >> 48;
		}else{
			return unpack("v", $str)[1] << 16 >> 16;
		}
	}

	/**
	 * Writes a 16-bit signed/unsigned little-endian number
	 */
	public static function writeLShort(int $value) : string{
		return pack("v", $value);
	}

	public static function readInt(string $str) : int{
		if(strlen($str) != 4) return 0;
		if(PHP_INT_SIZE === 8){
			return unpack("N", $str)[1] << 32 >> 32;
		}else{
			return unpack("N", $str)[1];
		}
	}

	public static function writeInt(int $value) : string{
		return pack("N", $value);
	}

	public static function readLInt(string $str) : int{
		if(PHP_INT_SIZE === 8){
			return unpack("V", $str)[1] << 32 >> 32;
		}else{
			return unpack("V", $str)[1];
		}
	}

	public static function writeLInt(int $value) : string{
		return pack("V", $value);
	}

	/**
	 * Reads a 32-bit float. Returns 0.0 on truncated input (see readInt guard).
	 */
	public static function readFloat(string $str) : float{
		if(strlen($str) < 4) return 0.0;
		return self::endianness() === self::BIG_ENDIAN ? unpack("f", $str)[1] : unpack("f", strrev($str))[1];
	}

	public static function writeFloat(float $value) : string{
		return self::endianness() === self::BIG_ENDIAN ? pack("f", $value) : strrev(pack("f", $value));
	}

	/**
	 * Reads a 32-bit little-endian float. Returns 0.0 on truncated input (see readInt guard).
	 */
	public static function readLFloat(string $str) : float{
		if(strlen($str) < 4) return 0.0;
		return self::endianness() === self::BIG_ENDIAN ? unpack("f", strrev($str))[1] : unpack("f", $str)[1];
	}

	public static function writeLFloat(float $value) : string{
		return self::endianness() === self::BIG_ENDIAN ? strrev(pack("f", $value)) : pack("f", $value);
	}

	public static function printFloat(float $value) : string{
		return preg_replace("/(\.\d+?)0+$/", "$1", sprintf("%F", $value));
	}

	/**
	 * Reads a 64-bit double. Returns 0.0 on truncated input (see readInt guard).
	 */
	public static function readDouble(string $str) : float{
		if(strlen($str) < 8) return 0.0;
		return self::endianness() === self::BIG_ENDIAN ? unpack("d", $str)[1] : unpack("d", strrev($str))[1];
	}

	public static function writeDouble(float $value) : string{
		return self::endianness() === self::BIG_ENDIAN ? pack("d", $value) : strrev(pack("d", $value));
	}

	/**
	 * Reads a 64-bit little-endian double. Returns 0.0 on truncated input (see readInt guard).
	 */
	public static function readLDouble(string $str) : float{
		if(strlen($str) < 8) return 0.0;
		return self::endianness() === self::BIG_ENDIAN ? unpack("d", strrev($str))[1] : unpack("d", $str)[1];
	}

	public static function writeLDouble(float $value) : string{
		return self::endianness() === self::BIG_ENDIAN ? strrev(pack("d", $value)) : pack("d", $value);
	}

	/**
	 * NOTE: the 32-bit fallback branch is dead code on 64-bit platforms and is kept for reference only.
	 */
	public static function readLong(string $x) : int{
		if(PHP_INT_SIZE === 8){
			if(strlen($x) < 8) return 0;
			$int = unpack("N*", $x);
			return ($int[1] << 32) | $int[2];
		}else{
			$value = "0";
			for($i = 0; $i < 8; $i += 2){
				$value = bcmul($value, "65536", 0);
				$value = bcadd($value, strval(self::readShort(substr($x, $i, 2))), 0);
			}

			if(bccomp($value, "9223372036854775807") == 1){
				$value = bcadd($value, "-18446744073709551616");
			}

			return (int) $value;
		}
	}

	public static function writeLong(int $value) : string{
		if(PHP_INT_SIZE === 8){
			return pack("NN", $value >> 32, $value & 0xFFFFFFFF);
		}else{
			$x = "";

			if(bccomp(strval($value), "0") == -1){
				$value = bcadd(strval($value), "18446744073709551616");
			}

			$x .= self::writeShort((int) bcmod(bcdiv($value, "281474976710656"), "65536"));
			$x .= self::writeShort((int) bcmod(bcdiv($value, "4294967296"), "65536"));
			$x .= self::writeShort((int) bcmod(bcdiv($value, "65536"), "65536"));
			$x .= self::writeShort((int) bcmod($value, "65536"));

			return $x;
		}
	}

	public static function readLLong(string $str) : int{
		return self::readLong(strrev($str));
	}

	public static function writeLLong(int $value) : string{
		return strrev(self::writeLong($value));
	}

}

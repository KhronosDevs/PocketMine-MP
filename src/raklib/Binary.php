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

namespace raklib;

use function bcadd;
use function bccomp;
use function bcdiv;
use function bcmod;
use function bcmul;
use function chr;
use function define;
use function defined;
use function ord;
use function pack;
use function strrev;
use function strval;
use function substr;
use function unpack;
use const PHP_INT_SIZE;

if(!defined("ENDIANNESS")){
	define("ENDIANNESS", (pack("d", 1) === "\77\360\0\0\0\0\0\0" ? Binary::BIG_ENDIAN : Binary::LITTLE_ENDIAN));
}

class Binary{
	const BIG_ENDIAN = 0x00;
	const LITTLE_ENDIAN = 0x01;

	/**
	 * Reads a 3-byte big-endian number. Returns null on truncated input (original null-tolerant behavior).
	 */
	public static function readTriad(string $str) : ?int{
		if(strlen($str) < 3){
			return null;
		}
		return unpack("N", "\x00" . $str)[1];
	}

	/**
	 * Writes a 3-byte big-endian number
	 */
	public static function writeTriad(int $value) : string{
		return substr(pack("N", $value), 1);
	}

	/**
	 * Reads a 3-byte little-endian number. Returns null on truncated input (original null-tolerant behavior).
	 */
	public static function readLTriad(string $str) : ?int{
		if(strlen($str) < 3){
			return null;
		}
		return unpack("V", $str . "\x00")[1];
	}

	/**
	 * Writes a 3-byte little-endian number
	 */
	public static function writeLTriad(int $value) : string{
		return substr(pack("V", $value), 0, -1);
	}

	/**
	 * Reads a byte boolean
	 */
	public static function readBool(string $b) : bool{
		return self::readByte($b, false) === 0 ? false : true;
	}

	/**
	 * Writes a byte boolean
	 */
	public static function writeBool(bool $b) : string{
		return self::writeByte($b === true ? 1 : 0);
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
	 * Reads a 16-bit unsigned big-endian number. Returns null on truncated input (original null-tolerant behavior).
	 */
	public static function readShort(string $str) : ?int{
		if(strlen($str) < 2){
			return null;
		}
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
	 * Reads a 16-bit signed/unsigned little-endian number. Returns null on truncated input (original null-tolerant behavior).
	 */
	public static function readLShort(string $str, bool $signed = true) : ?int{
		if(strlen($str) < 2){
			return null;
		}
		$unpacked = unpack("v", $str)[1];

		if($signed){
			if(PHP_INT_SIZE === 8){
				return $unpacked << 48 >> 48;
			}else{
				return $unpacked << 16 >> 16;
			}
		}else{
			return $unpacked;
		}
	}

	/**
	 * Writes a 16-bit signed/unsigned little-endian number
	 */
	public static function writeLShort(int $value) : string{
		return pack("v", $value);
	}

	public static function readInt(string $str) : int{
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
	 * Reads a 32-bit float. Returns null on truncated input (original null-tolerant behavior).
	 */
	public static function readFloat(string $str) : ?float{
		if(strlen($str) < 4){
			return null;
		}
		return ENDIANNESS === self::BIG_ENDIAN ? unpack("f", $str)[1] : unpack("f", strrev($str))[1];
	}

	public static function writeFloat(float $value) : string{
		return ENDIANNESS === self::BIG_ENDIAN ? pack("f", $value) : strrev(pack("f", $value));
	}

	/**
	 * Reads a 32-bit little-endian float. Returns null on truncated input (original null-tolerant behavior).
	 */
	public static function readLFloat(string $str) : ?float{
		if(strlen($str) < 4){
			return null;
		}
		return ENDIANNESS === self::BIG_ENDIAN ? unpack("f", strrev($str))[1] : unpack("f", $str)[1];
	}

	public static function writeLFloat(float $value) : string{
		return ENDIANNESS === self::BIG_ENDIAN ? strrev(pack("f", $value)) : pack("f", $value);
	}

	/**
	 * Reads a 64-bit double. Returns null on truncated input (original null-tolerant behavior).
	 */
	public static function readDouble(string $str) : ?float{
		if(strlen($str) < 8){
			return null;
		}
		return ENDIANNESS === self::BIG_ENDIAN ? unpack("d", $str)[1] : unpack("d", strrev($str))[1];
	}

	public static function writeDouble(float $value) : string{
		return ENDIANNESS === self::BIG_ENDIAN ? pack("d", $value) : strrev(pack("d", $value));
	}

	/**
	 * Reads a 64-bit little-endian double. Returns null on truncated input (original null-tolerant behavior).
	 */
	public static function readLDouble(string $str) : ?float{
		if(strlen($str) < 8){
			return null;
		}
		return ENDIANNESS === self::BIG_ENDIAN ? unpack("d", strrev($str))[1] : unpack("d", $str)[1];
	}

	public static function writeLDouble(float $value) : string{
		return ENDIANNESS === self::BIG_ENDIAN ? strrev(pack("d", $value)) : pack("d", $value);
	}

	public static function readLong(string $x) : int{
		if(PHP_INT_SIZE === 8){
			list(, $int1, $int2) = unpack("N*", $x);

			return ($int1 << 32) | $int2;
		}else{
			$value = "0";
			for($i = 0; $i < 8; $i += 2){
				$value = bcmul($value, "65536", 0);
				$value = bcadd($value, strval(self::readShort(substr($x, $i, 2))), 0);
			}

			if(bccomp($value, "9223372036854775807") == 1){
				$value = bcadd($value, "-18446744073709551616");
			}

			return $value;
		}
	}

	public static function writeLong(int $value) : string{
		if(PHP_INT_SIZE === 8){
			return pack("NN", $value >> 32, $value & 0xFFFFFFFF);
		}else{
			$x = "";

			if(bccomp($value, "0") == -1){
				$value = bcadd($value, "18446744073709551616");
			}

			$x .= self::writeShort(bcmod(bcdiv($value, "281474976710656"), "65536"));
			$x .= self::writeShort(bcmod(bcdiv($value, "4294967296"), "65536"));
			$x .= self::writeShort(bcmod(bcdiv($value, "65536"), "65536"));
			$x .= self::writeShort(bcmod($value, "65536"));

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

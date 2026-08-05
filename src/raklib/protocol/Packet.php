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
use function chr;
use function explode;
use function ord;
use function strlen;
use function substr;

abstract class Packet{
	public static $ID = -1;

	protected int $offset = 0;

	public ?string $buffer = null;

	public ?float $sendTime = null;

	protected function get(int|bool|null $len) : string{
		//note: null length (from a truncated getShort()) reproduces the original weak-mode behavior of
		//reading to the end of the buffer
		if($len < 0){
			$this->offset = strlen($this->buffer) - 1;

			return "";
		}elseif($len === true){
			return substr($this->buffer, $this->offset);
		}

		return $len === 1 ? $this->buffer[$this->offset++] : substr($this->buffer, ($this->offset += $len) - $len, $len);
	}

	protected function getLong(bool $signed = true) : int{
		return Binary::readLong($this->get(8), $signed);
	}

	protected function getInt() : int{
		return Binary::readInt($this->get(4));
	}

	protected function getShort(bool $signed = true) : ?int{
		return $signed ? Binary::readSignedShort($this->get(2)) : Binary::readShort($this->get(2));
	}

	protected function getTriad() : ?int{
		return Binary::readTriad($this->get(3));
	}

	protected function getLTriad() : ?int{
		return Binary::readLTriad($this->get(3));
	}

	protected function getByte() : int{
		return ord($this->buffer[$this->offset++]);
	}

	protected function getString() : string{
		return $this->get($this->getShort());
	}

	protected function getAddress(&$addr, &$port, &$version = null) : void{
		$version = $this->getByte();
		if($version === 4){
			$addr = ((~$this->getByte()) & 0xff) . "." . ((~$this->getByte()) & 0xff) . "." . ((~$this->getByte()) & 0xff) . "." . ((~$this->getByte()) & 0xff);
			$port = $this->getShort(false);
		}else{
			//TODO: IPv6
		}
	}

	protected function feof() : bool{
		return !isset($this->buffer[$this->offset]);
	}

	protected function put(string $str) : void{
		$this->buffer .= $str;
	}

	protected function putLong(int $v) : void{
		$this->buffer .= Binary::writeLong($v);
	}

	protected function putInt(int $v) : void{
		$this->buffer .= Binary::writeInt($v);
	}

	protected function putShort(int $v) : void{
		$this->buffer .= Binary::writeShort($v);
	}

	protected function putTriad(int $v) : void{
		$this->buffer .= Binary::writeTriad($v);
	}

	protected function putLTriad(int $v) : void{
		$this->buffer .= Binary::writeLTriad($v);
	}

	protected function putByte(int $v) : void{
		$this->buffer .= chr($v);
	}

	protected function putString(string $v) : void{
		$this->putShort(strlen($v));
		$this->put($v);
	}

	protected function putAddress(string $addr, int $port, int $version = 4) : void{
		$this->putByte($version);
		if($version === 4){
			foreach(explode(".", $addr) as $b){
				$this->putByte((~((int) $b)) & 0xff);
			}
			$this->putShort($port);
		}else{
			//IPv6
		}
	}

	public function encode() : void{
		$this->buffer = chr(static::$ID);
	}

	public function decode() : void{
		$this->offset = 1;
	}

	public function clean() : static{
		$this->buffer = null;
		$this->offset = 0;
		$this->sendTime = null;
		return $this;
	}
}

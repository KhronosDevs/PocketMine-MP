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



use raklib\RakLib;
use function str_pad;
use function strlen;

class OPEN_CONNECTION_REQUEST_1 extends Packet{
	public static $ID = 0x05;

	public int $protocol = RakLib::PROTOCOL;
	public int $mtuSize = 0;

	public function encode() : void{
		parent::encode();
		$this->put(RakLib::MAGIC);
		$this->putByte($this->protocol);
		$this->buffer = str_pad($this->buffer, $this->mtuSize, "\x00");
		//$this->put(str_repeat(chr(0x00), $this->mtuSize - 18));
	}

	public function decode() : void{
		parent::decode();
		$this->offset += 16; //Magic
		$this->protocol = $this->getByte();
		$this->mtuSize = strlen($this->buffer);
		//$this->mtuSize = strlen($this->get(true)) + 18;
	}
}

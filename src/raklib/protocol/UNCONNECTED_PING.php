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

class UNCONNECTED_PING extends Packet{
	public static $ID = 0x01;

	public int $pingID = 0;

	public function encode() : void{
		parent::encode();
		$this->putLong($this->pingID);
		$this->put(RakLib::MAGIC);
	}

	public function decode() : void{
		parent::decode();
		$this->pingID = $this->getLong();
		//magic
	}
}

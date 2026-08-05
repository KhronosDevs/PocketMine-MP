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

class UNCONNECTED_PONG extends Packet{
	public static $ID = 0x1c;

	public int $pingID = 0;
	public int $serverID = 0;
	public string $serverName = "";

	public function encode() : void{
		parent::encode();
		$this->putLong($this->pingID);
		$this->putLong($this->serverID);
		$this->put(RakLib::MAGIC);
		$this->putString($this->serverName);
	}

	public function decode() : void{
		parent::decode();
		$this->pingID = $this->getLong();
		$this->serverID = $this->getLong();
		$this->offset += 16; //magic
		$this->serverName = $this->getString();
	}
}

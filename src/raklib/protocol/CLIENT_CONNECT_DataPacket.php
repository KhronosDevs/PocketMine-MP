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

declare(strict_types=1);

namespace raklib\protocol;



class CLIENT_CONNECT_DataPacket extends Packet{
	public static $ID = 0x09;

	public int $clientID = 0;
	public int $sendPing = 0;
	public bool $useSecurity = false;

	public function encode() : void{
		parent::encode();
		$this->putLong($this->clientID);
		$this->putLong($this->sendPing);
		$this->putByte($this->useSecurity ? 1 : 0);
	}

	public function decode() : void{
		parent::decode();
		$this->clientID = $this->getLong();
		$this->sendPing = $this->getLong();
		$this->useSecurity = $this->getByte() > 0;
	}
}

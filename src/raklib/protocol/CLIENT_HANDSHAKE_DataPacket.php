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



class CLIENT_HANDSHAKE_DataPacket extends Packet{
	public static $ID = 0x13;

	public string $address = "";
	public int $port = 0;

	/** @var array[] */
	public array $systemAddresses = [
		["0.0.0.0", 0, 4],
		["0.0.0.0", 0, 4],
		["0.0.0.0", 0, 4],
		["0.0.0.0", 0, 4],
		["0.0.0.0", 0, 4],
		["0.0.0.0", 0, 4],
		["0.0.0.0", 0, 4],
		["0.0.0.0", 0, 4],
		["0.0.0.0", 0, 4],
		["0.0.0.0", 0, 4]
	];

	public int $sendPing = 0;
	public int $sendPong = 0;

	public function encode() : void{
		parent::encode();
		$this->putAddress($this->address, $this->port, 4);
		for($i = 0; $i < 10; ++$i){
			$addr = $this->systemAddresses[$i] ?? ["0.0.0.0", 0, 4];
			$this->putAddress($addr[0], (int) $addr[1], (int) $addr[2]);
		}
		$this->putLong($this->sendPing);
		$this->putLong($this->sendPong);
	}

	public function decode() : void{
		parent::decode();
		$this->getAddress($this->address, $this->port);
		for($i = 0; $i < 10; ++$i){
			$this->getAddress($addr, $port, $version);
			$this->systemAddresses[$i] = [$addr, $port, $version];
		}

		$this->sendPing = $this->getLong();
		$this->sendPong = $this->getLong();
	}
}

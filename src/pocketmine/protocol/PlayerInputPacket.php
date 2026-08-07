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

namespace pocketmine\protocol;



class PlayerInputPacket extends DataPacket{
	const NETWORK_ID = Info::PLAYER_INPUT_PACKET;

	public float $motX = 0.0;
	public float $motY = 0.0;

	public bool $jumping = false;
	public bool $sneaking = false;

	public function decode() : void{
		$this->motX = $this->getFloat();
		$this->motY = $this->getFloat();
		$flags = $this->getByte();
		$this->jumping = (($flags & 0x80) > 0);
		$this->sneaking = (($flags & 0x40) > 0);
	}

	public function encode() : void{

	}

}

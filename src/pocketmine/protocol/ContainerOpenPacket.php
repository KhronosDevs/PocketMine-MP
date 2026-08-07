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



class ContainerOpenPacket extends DataPacket{
	const NETWORK_ID = Info::CONTAINER_OPEN_PACKET;

	public int $windowid = 0;
	public int $type = 0;
	public int $slots = 0;
	public int $x = 0;
	public int $y = 0;
	public int $z = 0;
	public int $entityId = -1;

	public function decode() : void{

	}

	public function encode() : void{
		$this->reset();
		$this->putByte($this->windowid);
		$this->putByte($this->type);
		$this->putShort($this->slots);
		$this->putInt($this->x);
		$this->putInt($this->y);
		$this->putInt($this->z);
		$this->putLong($this->entityId);
	}

}

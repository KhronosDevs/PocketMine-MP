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

namespace pocketmine\network\protocol;



class MoveEntityPacket extends DataPacket{
	const NETWORK_ID = Info::MOVE_ENTITY_PACKET;

	public int $eid = 0;
	public float $x = 0.0;
	public float $y = 0.0;
	public float $z = 0.0;
	public float $yaw = 0.0;
	public float $headYaw = 0.0;
	public float $pitch = 0.0;

	public function decode() : void{
		$this->eid = $this->getLong();
		$this->x = $this->getFloat();
		$this->y = $this->getFloat();
		$this->z = $this->getFloat();
		$this->pitch = $this->getByte() * (360.0 / 256);
		$this->yaw = $this->getByte() * (360.0 / 256);
		$this->headYaw = $this->getByte() * (360.0 / 256);
	}

	public function encode() : void{
		$this->reset();
		$this->putLong($this->eid);
		$this->putFloat($this->x);
		$this->putFloat($this->y);
		$this->putFloat($this->z);
		$this->putByte((int) ($this->pitch / (360.0 / 256)));
		$this->putByte((int) ($this->yaw / (360.0 / 256)));
		$this->putByte((int) ($this->headYaw / (360.0 / 256)));
	}
}

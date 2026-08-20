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



class MobEffectPacket extends DataPacket{
	const NETWORK_ID = Info::MOB_EFFECT_PACKET;

	const EVENT_ADD = 1;
	const EVENT_MODIFY = 2;
	const EVENT_REMOVE = 3;

	public int $eid = 0;
	public int $eventId = 0;
	public int $effectId = 0;
	public int $amplifier = 0;
	public bool $particles = true;
	public int $duration = 0;

	public function decode() : void{

	}

	public function encode() : void{
		$this->reset();
		$this->putLong($this->eid);
		$this->putByte($this->eventId);
		$this->putByte($this->effectId);
		$this->putByte($this->amplifier);
		$this->putByte($this->particles ? 1 : 0);
		$this->putInt($this->duration);
	}

}

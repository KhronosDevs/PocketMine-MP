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



class PlayerActionPacket extends DataPacket{
	const NETWORK_ID = Info::PLAYER_ACTION_PACKET;

	const ACTION_START_BREAK = 0;
	const ACTION_ABORT_BREAK = 1;
	const ACTION_STOP_BREAK = 2;

	const ACTION_RELEASE_ITEM = 5;
	const ACTION_STOP_SLEEPING = 6;
	const ACTION_SPAWN_SAME_DIMENSION = 7;
	const ACTION_JUMP = 8;
	const ACTION_START_SPRINT = 9;
	const ACTION_STOP_SPRINT = 10;
	const ACTION_START_SNEAK = 11;
	const ACTION_STOP_SNEAK = 12;
	const ACTION_SPAWN_OVERWORLD = 13;
	const ACTION_SPAWN_NETHER = 14;

	public int $eid = 0;
	public int $action = 0;
	public int $x = 0;
	public int $y = 0;
	public int $z = 0;
	public int $face = 0;

	public function decode() : void{
		$this->eid = $this->getLong();
		$this->action = $this->getInt();
		$this->x = $this->getInt();
		$this->y = $this->getInt();
		$this->z = $this->getInt();
		$this->face = $this->getInt();
	}

	public function encode() : void{
		$this->reset();
		$this->putLong($this->eid);
		$this->putInt($this->action);
		$this->putInt($this->x);
		$this->putInt($this->y);
		$this->putInt($this->z);
		$this->putInt($this->face);
	}

}

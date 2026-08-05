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



class StartGamePacket extends DataPacket{
	const NETWORK_ID = Info::START_GAME_PACKET;

	public int $seed = 0;
	public int $dimension = 0;
	public int $generator = 0;
	public int $gamemode = 0;
	public int $eid = 0;
	public int $spawnX = 0;
	public int $spawnY = 0;
	public int $spawnZ = 0;
	public float $x = 0.0;
	public float $y = 0.0;
	public float $z = 0.0;
	public string $unknown = "";

	public function decode() : void{

	}

	public function encode() : void{
		$this->reset();
		$this->putInt($this->seed);
		$this->putByte($this->dimension);
		$this->putInt($this->generator);
		$this->putInt($this->gamemode);
		$this->putLong($this->eid);
		$this->putInt($this->spawnX);
		$this->putInt($this->spawnY);
		$this->putInt($this->spawnZ);
		$this->putFloat($this->x);
		$this->putFloat($this->y);
		$this->putFloat($this->z);
		$this->putByte(1);
		$this->putByte(1);
		$this->putByte(0);
		$this->putString($this->unknown);
	}

}

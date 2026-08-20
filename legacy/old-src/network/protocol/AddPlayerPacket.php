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



use pocketmine\item\Item;
use pocketmine\utils\Binary;
use pocketmine\utils\UUID;

class AddPlayerPacket extends DataPacket{
	const NETWORK_ID = Info::ADD_PLAYER_PACKET;

	public UUID $uuid;
	public string $username = "";
	public int $eid = 0;
	public float $x = 0.0;
	public float $y = 0.0;
	public float $z = 0.0;
	public float $speedX = 0.0;
	public float $speedY = 0.0;
	public float $speedZ = 0.0;
	public float $pitch = 0.0;
	public float $yaw = 0.0;
	public Item $item;
	/** @var array */
	public $metadata = [];

	public function decode() : void{

	}

	public function encode() : void{
		$this->reset();
		$this->putUUID($this->uuid);
		$this->putString($this->username);
		$this->putLong($this->eid);
		$this->putFloat($this->x);
		$this->putFloat($this->y);
		$this->putFloat($this->z);
		$this->putFloat($this->speedX);
		$this->putFloat($this->speedY);
		$this->putFloat($this->speedZ);
		$this->putFloat($this->yaw);
		$this->putFloat($this->yaw); //TODO headrot
		$this->putFloat($this->pitch);
		$this->putSlot($this->item);

		$meta = Binary::writeMetadata($this->metadata);
		$this->put($meta);
	}

}

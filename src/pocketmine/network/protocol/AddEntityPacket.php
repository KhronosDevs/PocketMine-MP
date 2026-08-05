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



use pocketmine\utils\Binary;
use function count;

class AddEntityPacket extends DataPacket{
	const NETWORK_ID = Info::ADD_ENTITY_PACKET;

	public int $eid = 0;
	public int $type = 0;
	public float $x = 0.0;
	public float $y = 0.0;
	public float $z = 0.0;
	public float $speedX = 0.0;
	public float $speedY = 0.0;
	public float $speedZ = 0.0;
	public float $yaw = 0.0;
	public float $pitch = 0.0;
	public int $modifiers = 0;
	/** @var array */
	public $metadata = [];
	/** @var array[] */
	public array $links = [];

	public function decode() : void{

	}

	public function encode() : void{
		$this->reset();
		$this->putLong($this->eid);
		$this->putInt($this->type);
		$this->putFloat($this->x);
		$this->putFloat($this->y);
		$this->putFloat($this->z);
		$this->putFloat($this->speedX);
		$this->putFloat($this->speedY);
		$this->putFloat($this->speedZ);
		$this->putFloat($this->yaw * 0.71111);
		$this->putFloat($this->pitch * 0.71111);
		$this->putInt($this->modifiers);
		$meta = Binary::writeMetadata($this->metadata);
		$this->put($meta);
		$this->putShort(count($this->links));
		foreach($this->links as $link){
			$this->putLong($link[0]);
			$this->putLong($link[1]);
			$this->putByte($link[2]);
		}
	}

}

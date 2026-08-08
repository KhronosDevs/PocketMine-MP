<?php

declare(strict_types=1);

namespace pocketmine\protocol;

class AddItemEntityPacket extends DataPacket{
	const NETWORK_ID = Info::ADD_ITEM_ENTITY_PACKET;

	public int $eid = 0;
	/** @var array{0: int, 1: int, 2: int, 3: mixed} slot item */
	public array $item = [0, 0, 0, null];
	public float $x = 0.0;
	public float $y = 0.0;
	public float $z = 0.0;
	public float $speedX = 0.0;
	public float $speedY = 0.0;
	public float $speedZ = 0.0;

	public function decode() : void{

	}

	public function encode() : void{
		$this->reset();
		$this->putLong($this->eid);
		$this->putSlot($this->item);
		$this->putFloat($this->x);
		$this->putFloat($this->y);
		$this->putFloat($this->z);
		$this->putFloat($this->speedX);
		$this->putFloat($this->speedY);
		$this->putFloat($this->speedZ);
	}

}

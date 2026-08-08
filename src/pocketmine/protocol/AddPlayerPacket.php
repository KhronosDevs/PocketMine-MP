<?php

declare(strict_types=1);

namespace pocketmine\protocol;

use pocketmine\utils\Binary;
use pocketmine\utils\UUID;

class AddPlayerPacket extends DataPacket{
	const NETWORK_ID = Info::ADD_PLAYER_PACKET;

	public UUID $uuid;
	public string $username = '';
	public int $eid = 0;
	public float $x = 0.0;
	public float $y = 0.0;
	public float $z = 0.0;
	public float $speedX = 0.0;
	public float $speedY = 0.0;
	public float $speedZ = 0.0;
	public float $yaw = 0.0;
	public float $pitch = 0.0;
	/** @var array{0: int, 1: int, 2: int, 3: mixed} slot item */
	public array $item = [0, 0, 0, null];
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

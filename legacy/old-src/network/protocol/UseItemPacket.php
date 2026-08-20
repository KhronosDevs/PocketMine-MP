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

class UseItemPacket extends DataPacket{
	const NETWORK_ID = Info::USE_ITEM_PACKET;

	public int $x = 0;
	public int $y = 0;
	public int $z = 0;
	public int $face = 0;
	public Item $item;
	public float $fx = 0.0;
	public float $fy = 0.0;
	public float $fz = 0.0;
	public float $posX = 0.0;
	public float $posY = 0.0;
	public float $posZ = 0.0;
	public int $slot = 0;

	public function decode() : void{
		$this->x = $this->getInt();
		$this->y = $this->getInt();
		$this->z = $this->getInt();
		$this->face = $this->getByte();
		$this->fx = $this->getFloat();
		$this->fy = $this->getFloat();
		$this->fz = $this->getFloat();
		$this->posX = $this->getFloat();
		$this->posY = $this->getFloat();
		$this->posZ = $this->getFloat();
		$this->slot = $this->getInt();
		$this->item = $this->getSlot();
	}

	public function encode() : void{

	}
}

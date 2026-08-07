<?php



/*
 *
 *  _____   _____   __   _   _   _____  __    __  _____
 * /  ___| | ____| |  \ | | | | /  ___/ \ \  / / /  ___/
 * | |     | |__   |   \| | | | | |___   \ \/ /  | |___
 * | |  _  |  __|  | |\   | | | \___  \   \  /   \___  \
 * | |_| | | |___  | | \  | | |  ___| |   / /     ___| |
 * \_____/ |_____| |_|  \_| |_| /_____/  /_/     /_____/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author iTX Technologies
 * @link https://itxtech.org
 *
 */

declare(strict_types=1);

namespace pocketmine\network\protocol;



use pocketmine\item\Item;

class ItemFrameDropItemPacket extends DataPacket{

	const NETWORK_ID = Info::ITEM_FRAME_DROP_ITEM_PACKET;

	public int $x = 0;
	public int $y = 0;
	public int $z = 0;
	public Item $dropItem;

	public function decode() : void{
		$this->z = $this->getInt();
		$this->y = $this->getInt();
		$this->x = $this->getInt();
		$this->dropItem = $this->getSlot();
	}

	public function encode() : void{
	}
}

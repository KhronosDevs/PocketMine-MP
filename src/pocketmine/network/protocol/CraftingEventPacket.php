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



use pocketmine\utils\UUID;

class CraftingEventPacket extends DataPacket{
	const NETWORK_ID = Info::CRAFTING_EVENT_PACKET;

	public int $windowId = 0;
	public int $type = 0;
	public UUID $id;
	/** @var \pocketmine\item\Item[] */
	public array $input = [];
	/** @var \pocketmine\item\Item[] */
	public array $output = [];

	public function clean() : static{
		$this->input = [];
		$this->output = [];
		return parent::clean();
	}

	public function decode() : void{
		$this->windowId = $this->getByte();
		$this->type = $this->getInt();
		$this->id = $this->getUUID();

		$size = $this->getInt();
		for($i = 0; $i < $size && $i < 128; ++$i){
			$this->input[] = $this->getSlot();
		}

		$size = $this->getInt();
		for($i = 0; $i < $size && $i < 128; ++$i){
			$this->output[] = $this->getSlot();
		}
	}

	public function encode() : void{

	}

}

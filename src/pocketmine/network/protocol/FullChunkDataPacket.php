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



use function strlen;

class FullChunkDataPacket extends DataPacket{
	const NETWORK_ID = Info::FULL_CHUNK_DATA_PACKET;

	const ORDER_COLUMNS = 0;
	const ORDER_LAYERED = 1;

	public int $chunkX = 0;
	public int $chunkZ = 0;
	public int $order = self::ORDER_COLUMNS;
	public string $data = "";

	public function decode() : void{

	}

	public function encode() : void{
		$this->reset();
		$this->putInt($this->chunkX);
		$this->putInt($this->chunkZ);
		$this->putByte($this->order);
		$this->putInt(strlen($this->data));
		$this->put($this->data);
	}

}

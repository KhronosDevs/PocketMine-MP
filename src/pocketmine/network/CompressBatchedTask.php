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

namespace pocketmine\network;



use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use function serialize;
use function unserialize;
use function zlib_encode;
use const ZLIB_ENCODING_DEFLATE;

class CompressBatchedTask extends AsyncTask{

	public int $level = 7;
	public ?string $data;
	public ?string $final = null;
	public string $targets;

	public function __construct(string $data, array $targets, int $level = 7){
		$this->data = $data;
		$this->targets = serialize($targets);
		$this->level = $level;
	}

	public function onRun() : void{
		try{
			$this->final = zlib_encode($this->data, ZLIB_ENCODING_DEFLATE, $this->level);
			$this->data = null;
		}catch(\Throwable $e){

		}
	}

	public function onCompletion(Server $server) : void{
		$server->broadcastPacketsCallback($this->final, unserialize($this->targets));
	}
}

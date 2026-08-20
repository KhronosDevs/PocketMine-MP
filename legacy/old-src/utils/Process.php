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

namespace pocketmine\utils;

use function fclose;
use function getmypid;
use function getmyuid;
use function proc_close;
use function proc_open;
use function stream_get_contents;

final class Process{

	private function __construct(){
		//NOOP
	}

	public static function execute(string $command, &$stdout = null, &$stderr = null) : int{
		$process = proc_open($command, [
			["pipe", "r"],
			["pipe", "w"],
			["pipe", "w"]
		], $pipes);

		if($process === false){
			$stderr = "Failed to open process";
			$stdout = "";

			return -1;
		}

		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);

		foreach($pipes as $p){
			fclose($p);
		}

		return proc_close($process);
	}

	public static function pid() : int{
		$result = getmypid();
		if($result === false){
			throw new \LogicException("getmypid() doesn't work on this platform");
		}
		return $result;
	}

	public static function uid() : int{
		$result = getmyuid();
		if($result === false){
			throw new \LogicException("getmyuid() doesn't work on this platform");
		}
		return $result;
	}
}

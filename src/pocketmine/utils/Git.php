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



namespace pocketmine\utils;

use function str_repeat;
use function strlen;
use function trim;

final class Git{

	private function __construct(){
		//NOOP
	}

	public static function getRepositoryState(string $dir, bool &$dirty) {
		if(Process::execute("git -C \"$dir\" rev-parse HEAD", $out) === 0 && $out !== false && strlen($out = trim($out)) === 40){
			if(Process::execute("git -C \"$dir\" diff --quiet") === 1 || Process::execute("git -C \"$dir\" diff --cached --quiet") === 1){ //Locally-modified
				$dirty = true;
			}
			return $out;
		}
		return null;
	}

	public static function getRepositoryStatePretty(string $dir) : string{
		$dirty = false;
		$detectedHash = self::getRepositoryState($dir, $dirty);
		if($detectedHash !== null){
			return $detectedHash . ($dirty ? "-dirty" : "");
		}
		return str_repeat("00", 20);
	}
}

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
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author iTX Technologies
 * @link https://itxtech.org
 *
 */

declare(strict_types=1);

namespace pocketmine\block;

class SlimeBlock extends Solid{

	protected $id = self::SLIME_BLOCK;

	public function __construct(?int $meta = 15){
		$this->meta = $meta;
	}

	public function hasEntityCollision() : bool {
		return true;
	}

	public function getHardness() : int|float {
		return 0;
	}

	public function getName() : string{
		return "Slime Block";
	}
}

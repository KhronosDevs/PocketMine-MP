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

namespace pocketmine\item;

class IronHelmet extends Armor{
	public function __construct(?int $meta = 0, int $count = 1){
		parent::__construct(self::IRON_HELMET, $meta, $count, "Iron Helmet");
	}

	public function getArmorTier() : int {
		return Armor::TIER_IRON;
	}

	public function getArmorType() : int {
		return Armor::TYPE_HELMET;
	}

	public function getMaxDurability() : int {
		return 166;
	}

	public function getArmorValue() : int {
		return 2;
	}

	public function isHelmet() : bool {
		return true;
	}
}

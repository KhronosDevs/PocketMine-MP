<?php

declare(strict_types=1);



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

namespace pocketmine\inventory;

use pocketmine\item\Item;
use pocketmine\tile\Furnace;

class FurnaceInventory extends ContainerInventory{

	const SMELTING = 0;
	const FUEL = 1;
	const RESULT = 2;

	public function __construct(Furnace $tile){
		parent::__construct($tile, InventoryType::get(InventoryType::FURNACE));
	}

	/**
	 * @return Furnace
	 */
	public function getHolder() : Furnace{
		return $this->holder;
	}

	/**
	 * @return Item
	 */
	public function getResult() : Item{
		return $this->getItem(self::RESULT);
	}

	/**
	 * @return Item
	 */
	public function getFuel() : Item{
		return $this->getItem(self::FUEL);
	}

	/**
	 * @return Item
	 */
	public function getSmelting() : Item{
		return $this->getItem(self::SMELTING);
	}

	/**
	 * @return bool
	 */
	public function setResult(Item $item) : bool{
		return $this->setItem(self::RESULT, $item);
	}

	/**
	 * @return bool
	 */
	public function setFuel(Item $item) : bool{
		return $this->setItem(self::FUEL, $item);
	}

	/**
	 * @return bool
	 */
	public function setSmelting(Item $item) : bool{
		return $this->setItem(self::SMELTING, $item);
	}

	public function onSlotChange($index, $before, $send) : void{
		parent::onSlotChange($index, $before, $send);

		$this->getHolder()->scheduleUpdate();
	}
}

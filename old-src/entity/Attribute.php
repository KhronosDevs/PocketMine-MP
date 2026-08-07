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

namespace pocketmine\entity;

use pocketmine\Server;
use function max;
use function min;

class Attribute{

	const ABSORPTION = 0;
	const SATURATION = 1;
	const EXHAUSTION = 2;
	const KNOCKBACK_RESISTANCE = 3;
	const HEALTH = 4;
	const MOVEMENT_SPEED = 5;
	const FOLLOW_RANGE = 6;
	const HUNGER = 7;
	const FOOD = 7;
	const ATTACK_DAMAGE = 8;
	const EXPERIENCE_LEVEL = 9;
	const EXPERIENCE = 10;

	private int $id;
	protected float $minValue;
	protected float $maxValue;
	protected float $defaultValue;
	protected float $currentValue;
	protected string $name;
	protected bool $shouldSend;

	protected bool $desynchronized = true;

	/** @var Attribute[] */
	protected static array $attributes = [];

	public static function init() : void{
		self::addAttribute(self::ABSORPTION, "generic.absorption", 0.00, 340282346638528859811704183484516925440.00, 0.00);
		self::addAttribute(self::SATURATION, "player.saturation", 0.00, 20.00, 5.00);
		self::addAttribute(self::EXHAUSTION, "player.exhaustion", 0.00, 5.00, 0.41);
		self::addAttribute(self::KNOCKBACK_RESISTANCE, "generic.knockbackResistance", 0.00, 1.00, 0.00);
		self::addAttribute(self::HEALTH, "generic.health", 0.00, 20.00, 20.00);
		self::addAttribute(self::MOVEMENT_SPEED, "generic.movementSpeed", 0.00, 340282346638528859811704183484516925440.00, 0.10);
		self::addAttribute(self::FOLLOW_RANGE, "generic.followRange", 0.00, 2048.00, 16.00, false);
		self::addAttribute(self::HUNGER, "player.hunger", 0.00, 20.00, 20.00);
		self::addAttribute(self::ATTACK_DAMAGE, "generic.attackDamage", 0.00, 340282346638528859811704183484516925440.00, 1.00, false);
		self::addAttribute(self::EXPERIENCE_LEVEL, "player.level", 0.00, 2147483647.00, 0.00);
		self::addAttribute(self::EXPERIENCE, "player.experience", 0.00, 1.00, 0.00);
	}

	/**
	 * @param int    $id
	 * @param string $name
	 * @param float  $minValue
	 * @param float  $maxValue
	 * @param float  $defaultValue
	 * @param bool   $shouldSend
	 *
	 * @return Attribute
	 */
	public static function addAttribute(int $id, string $name, float $minValue, float $maxValue, float $defaultValue, bool $shouldSend = true) : Attribute{
		if($minValue > $maxValue || $defaultValue > $maxValue || $defaultValue < $minValue){
			throw new \InvalidArgumentException("Invalid ranges: min value: $minValue, max value: $maxValue, $defaultValue: $defaultValue");
		}

		return self::$attributes[(int) $id] = new Attribute($id, $name, $minValue, $maxValue, $defaultValue, $shouldSend);
	}

	/**
	 * @param $id
	 *
	 * @return null|Attribute
	 */
	public static function getAttribute(int $id) : ?Attribute{
		return isset(self::$attributes[$id]) ? clone self::$attributes[$id] : null;
	}

	/**
	 * @param $name
	 *
	 * @return null|Attribute
	 */
	public static function getAttributeByName(string $name) : ?Attribute{
		foreach(self::$attributes as $a){
			if($a->getName() === $name){
				return clone $a;
			}
		}

		return null;
	}

	private function __construct(int $id, string $name, float $minValue, float $maxValue, float $defaultValue, bool $shouldSend = true){
		$this->id = $id;
		$this->name = $name;
		$this->minValue = $minValue;
		$this->maxValue = $maxValue;
		$this->defaultValue = $defaultValue;
		$this->shouldSend = $shouldSend;

		$this->currentValue = $this->defaultValue;
	}

	public function getMinValue() : float{
		return $this->minValue;
	}

	public function setMinValue(float $minValue) : self{
		if($minValue > $this->getMaxValue()){
			throw new \InvalidArgumentException("Value $minValue is bigger than the maxValue!");
		}

		if($this->minValue != $minValue){
			$this->desynchronized = true;
			$this->minValue = $minValue;
		}
		return $this;
	}

	public function getMaxValue() : float{
		return $this->maxValue;
	}

	public function setMaxValue(float $maxValue) : self{
		if($maxValue < $this->getMinValue()){
			throw new \InvalidArgumentException("Value $maxValue is bigger than the minValue!");
		}

		if($this->maxValue != $maxValue){
			$this->desynchronized = true;
			$this->maxValue = $maxValue;
		}
		return $this;
	}

	public function getDefaultValue() : float{
		return $this->defaultValue;
	}

	public function setDefaultValue(float $defaultValue) : self{
		if($defaultValue > $this->getMaxValue() || $defaultValue < $this->getMinValue()){
			throw new \InvalidArgumentException("Value $defaultValue exceeds the range!");
		}

		if($this->defaultValue !== $defaultValue){
			$this->desynchronized = true;
			$this->defaultValue = $defaultValue;
		}
		return $this;
	}

	public function getValue() : float{
		return $this->currentValue;
	}

	public function setValue(float $value, bool $fit = true, bool $shouldSend = false) : self{
		if($value > $this->getMaxValue() || $value < $this->getMinValue()){
			if(!$fit){
				Server::getInstance()->getLogger()->error("[Attribute / {$this->getName()}] Value $value exceeds the range!");
			}
			$value = min(max($value, $this->getMinValue()), $this->getMaxValue());
		}

		if($this->currentValue != $value){
			$this->desynchronized = true;
			$this->currentValue = $value;
		}

		if($shouldSend){
			$this->desynchronized = true;
		}
		return $this;
	}

	public function getName(): string{
		return $this->name;
	}

	public function getId() : int{
		return $this->id;
	}

	public function isSyncable() : bool{
		return $this->shouldSend;
	}

	public function isDesynchronized() : bool{
		return $this->shouldSend && $this->desynchronized;
	}

	public function markSynchronized(bool $synced = true) : void{
		$this->desynchronized = !$synced;
	}
}

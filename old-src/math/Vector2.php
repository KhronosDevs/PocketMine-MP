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

namespace pocketmine\math;

use pocketmine\utils\Random;
use function abs;
use function pi;
use function pow;
use function round;
use function sqrt;

/**
 * WARNING: This class is available on the PocketMine-MP Zephir project.
 * If this class is modified, remember to modify the PHP C extension.
 */
class Vector2{
	public float $x;
	public float $y;

	public function __construct(float $x = 0, float $y = 0){
		$this->x = $x;
		$this->y = $y;
	}

	public function getX() : float{
		return $this->x;
	}

	public function getY() : float{
		return $this->y;
	}

	public function getFloorX() : int{
		return (int) $this->x;
	}

	public function getFloorY() : int{
		return (int) $this->y;
	}

	/**
	 * @param Vector2|float $x
	 */
	public function add(Vector2|float $x, float $y = 0) : Vector2{
		if($x instanceof Vector2){
			return $this->add($x->x, $x->y);
		}else{
			return new Vector2($this->x + $x, $this->y + $y);
		}
	}

	/**
	 * @param Vector2|float $x
	 */
	public function subtract(Vector2|float $x, float $y = 0) : Vector2{
		if($x instanceof Vector2){
			return $this->add(-$x->x, -$x->y);
		}else{
			return $this->add(-$x, -$y);
		}
	}

	public function ceil() : Vector2{
		return new Vector2((int) ($this->x + 1), (int) ($this->y + 1));
	}

	public function floor() : Vector2{
		return new Vector2((int) $this->x, (int) $this->y);
	}

	public function round() : Vector2{
		return new Vector2(round($this->x), round($this->y));
	}

	public function abs() : Vector2{
		return new Vector2(abs($this->x), abs($this->y));
	}

	public function multiply(float $number) : Vector2{
		return new Vector2($this->x * $number, $this->y * $number);
	}

	public function divide(float $number) : Vector2{
		return new Vector2($this->x / $number, $this->y / $number);
	}

	/**
	 * @param Vector2|float $x
	 */
	public function distance(Vector2|float $x, float $y = 0) : float{
		if($x instanceof Vector2){
			return sqrt($this->distanceSquared($x->x, $x->y));
		}else{
			return sqrt($this->distanceSquared($x, $y));
		}
	}

	/**
	 * @param Vector2|float $x
	 */
	public function distanceSquared(Vector2|float $x, float $y = 0) : float{
		if($x instanceof Vector2){
			return $this->distanceSquared($x->x, $x->y);
		}else{
			return pow($this->x - $x, 2) + pow($this->y - $y, 2);
		}
	}

	public function length() : float{
		return sqrt($this->lengthSquared());
	}

	public function lengthSquared() : float{
		return $this->x * $this->x + $this->y * $this->y;
	}

	public function normalize() : Vector2{
		$len = $this->lengthSquared();
		if($len != 0){
			return $this->divide(sqrt($len));
		}

		return new Vector2(0, 0);
	}

	public function dot(Vector2 $v) : float{
		return $this->x * $v->x + $this->y * $v->y;
	}

	public function __toString() : string{
		return "Vector2(x=" . $this->x . ",y=" . $this->y . ")";
	}

	public static function createRandomDirection(Random $random) : Vector2{
		return VectorMath::getDirection2D($random->nextFloat() * 2 * pi());
	}
}

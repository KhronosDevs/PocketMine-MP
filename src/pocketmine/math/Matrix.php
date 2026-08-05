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

use function implode;
use function max;
use function substr;

class Matrix implements \ArrayAccess{
	private array $matrix = [];
	private int $rows = 0;
	private int $columns = 0;

	public function offsetExists(mixed $offset): bool{
		return isset($this->matrix[(int) $offset]);
	}

	public function offsetGet(mixed $offset): mixed{
		return $this->matrix[(int) $offset];
	}

	public function offsetSet(mixed $offset, mixed $value): void{
		$this->matrix[(int) $offset] = $value;
	}

	public function offsetUnset(mixed $offset): void{
		unset($this->matrix[(int) $offset]);
	}

	public function __construct(int $rows, int $columns, array $set = []){
		$this->rows = max(1, $rows);
		$this->columns = max(1, $columns);
		$this->set($set);
	}

	public function set(array $m) : void{
		for($r = 0; $r < $this->rows; ++$r){
			$this->matrix[$r] = [];
			for($c = 0; $c < $this->columns; ++$c){
				$this->matrix[$r][$c] = isset($m[$r][$c]) ? $m[$r][$c] : 0;
			}
		}
	}

	public function getRows() : int{
		return $this->rows;
	}

	public function getColumns() : int{
		return $this->columns;
	}

	public function setElement(int $row, int $column, mixed $value) : bool{
		if($row > $this->rows || $row < 0 || $column > $this->columns || $column < 0){
			return false;
		}
		$this->matrix[$row][$column] = $value;

		return true;
	}

	public function getElement(int $row, int $column) : mixed{
		if($row > $this->rows || $row < 0 || $column > $this->columns || $column < 0){
			return false;
		}

		return $this->matrix[$row][$column];
	}

	public function isSquare() : bool{
		return $this->rows === $this->columns;
	}

	public function add(Matrix $matrix) : Matrix|false{
		if($this->rows !== $matrix->getRows() || $this->columns !== $matrix->getColumns()){
			return false;
		}
		$result = new Matrix($this->rows, $this->columns);
		for($r = 0; $r < $this->rows; ++$r){
			for($c = 0; $c < $this->columns; ++$c){
				$result->setElement($r, $c, $this->matrix[$r][$c] + $matrix->getElement($r, $c));
			}
		}

		return $result;
	}

	public function substract(Matrix $matrix) : Matrix|false{
		if($this->rows !== $matrix->getRows() || $this->columns !== $matrix->getColumns()){
			return false;
		}
		$result = clone $this;
		for($r = 0; $r < $this->rows; ++$r){
			for($c = 0; $c < $this->columns; ++$c){
				$result->setElement($r, $c, $this->matrix[$r][$c] - $matrix->getElement($r, $c));
			}
		}

		return $result;
	}

	public function multiplyScalar(float $number) : Matrix{
		$result = clone $this;
		for($r = 0; $r < $this->rows; ++$r){
			for($c = 0; $c < $this->columns; ++$c){
				$result->setElement($r, $c, $this->matrix[$r][$c] * $number);
			}
		}

		return $result;
	}

	public function divideScalar(float $number) : Matrix{
		$result = clone $this;
		for($r = 0; $r < $this->rows; ++$r){
			for($c = 0; $c < $this->columns; ++$c){
				$result->setElement($r, $c, $this->matrix[$r][$c] / $number);
			}
		}

		return $result;
	}

	public function transpose() : Matrix{
		$result = new Matrix($this->columns, $this->rows);
		for($r = 0; $r < $this->rows; ++$r){
			for($c = 0; $c < $this->columns; ++$c){
				$result->setElement($c, $r, $this->matrix[$r][$c]);
			}
		}

		return $result;
	}

	//Naive Matrix product, O(n^3)
	public function product(Matrix $matrix) : Matrix|false{
		if($this->columns !== $matrix->getRows()){
			return false;
		}
		$c = $matrix->getColumns();
		$result = new Matrix($this->rows, $c);
		for($i = 0; $i < $this->rows; ++$i){
			for($j = 0; $j < $c; ++$j){
				$sum = 0;
				for($k = 0; $k < $this->columns; ++$k){
					$sum += $this->matrix[$i][$k] * $matrix->getElement($k, $j);
				}
				$result->setElement($i, $j, $sum);
			}
		}

		return $result;
	}

	//Computation of the determinant of 2x2 and 3x3 matrices
	public function determinant() : int|float|false{
		if($this->isSquare() !== true){
			return false;
		}
		switch($this->rows){
			case 1:
				return 0;
			case 2:
				return $this->matrix[0][0] * $this->matrix[1][1] - $this->matrix[0][1] * $this->matrix[1][0];
			case 3:
				return $this->matrix[0][0] * $this->matrix[1][1] * $this->matrix[2][2] + $this->matrix[0][1] * $this->matrix[1][2] * $this->matrix[2][0] + $this->matrix[0][2] * $this->matrix[1][0] * $this->matrix[2][1] - $this->matrix[2][0] * $this->matrix[1][1] * $this->matrix[0][2] - $this->matrix[2][1] * $this->matrix[1][2] * $this->matrix[0][0] - $this->matrix[2][2] * $this->matrix[1][0] * $this->matrix[0][1];
		}

		return false;
	}

	public function __toString() : string{
		$s = "";
		for($r = 0; $r < $this->rows; ++$r){
			$s .= implode(",", $this->matrix[$r]) . ";";
		}

		return "Matrix({$this->rows}x{$this->columns};" . substr($s, 0, -1) . ")";
	}

}

<?php



/*
 * PocketMine Standard PHP Library
 * Copyright (C) 2014 PocketMine Team <https://github.com/PocketMine/PocketMine-SPL>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
*/

declare(strict_types=1);

class SplFixedByteArray extends SplFixedArray{

	private bool $convert;

	public function __construct(int $size, bool $convert = false){
		parent::__construct($size);
		$this->convert = $convert;
	}

	/**
	 * @return string|int[]
	 */
	public function chunk(int $start, int $size, bool $normalize = true) : string|array{
		$end = $start + $size;
		if($normalize && $this->convert){
			$d = "";
			for($i = $start; $i < $end; ++$i){
				$d .= chr($this[$i]);
			}
		}else{
			$d = [];
			for($i = $start; $i < $end; ++$i){
				$d[] = $this[$i];
			}
		}
		return $d;
	}

	/**
	 * @param string $str
	 * @param bool   $convert
	 *
	 * @return SplFixedByteArray
	 */
	public static function fromString(string $str, bool $convert = false) : self{
		$len = strlen($str);
		$ob = new self($len, $convert);

		if($convert){
			for($i = 0; $i < $len; ++$i){
				$ob[$i] = ord($str[$i]);
			}
		}else{
			for($i = 0; $i < $len; ++$i){
				$ob[$i] = $str[$i];
			}
		}

		return $ob;
	}

	/**
	 * @param string $str
	 * @param int    $size
	 * @param int    $start
	 * @param bool   $convert
	 *
	 * @return SplFixedByteArray
	 */
	public static function fromStringChunk(string $str, int $size, int $start = 0, bool $convert = false) : self{
		$ob = new self($size, $convert);

		if($convert){
			for($i = 0; $i < $size; ++$i){
				$ob[$i] = ord($str[$i + $start]);
			}
		}else{
			for($i = 0; $i < $size; ++$i){
				$ob[$i] = $str[$i + $start];
			}
		}

		return $ob;
	}

	public function toString() : string{
		$result = "";
		if($this->convert){
			for($i = 0; $i < $this->getSize(); ++$i){
				$result .= chr($this[$i]);
			}
		}else{
			for($i = 0; $i < $this->getSize(); ++$i){
				$result .= $this[$i];
			}
		}
		return $result;
	}

	public function __toString() : string{
		return $this->toString();
	}
}

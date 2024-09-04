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

namespace pocketmine\nbt\tag;

use pocketmine\nbt\NBT;
use RuntimeException;

use function get_class;

#include <rules/NBT.h>

class CompoundTag extends NamedTag implements \ArrayAccess{

	/**
	 * @param string     $name
	 * @param NamedTag[] $value
	 */
	public function __construct($name = "", $value = []){
		$this->__name = $name;
		foreach($value as $tag){
			$this->{$tag->getName()} = $tag;
		}
	}

	public function getCount(){
		$count = 0;
		foreach($this as $tag){
			if($tag instanceof Tag){
				++$count;
			}
		}

		return $count;
	}

	/**
	 * @return Tag|null
	 */
	public function getTag(string $name){
		return $this->{$name} ?? null;
	}

	public function getListTag(string $name){
		$tag = $this->getTag($name);

		if($tag !== null && !($tag instanceof ListTag)){
			throw new RuntimeException("Expected a tag of type " . ListTag::class . ", got " . get_class($tag));
		}

		return $tag;
	}

	public function getCompoundTag(string $name){
		$tag = $this->getTag($name);

		if($tag !== null && !($tag instanceof CompoundTag)){
			throw new RuntimeException("Expected a tag of type " . CompoundTag::class . ", got " . get_class($tag));
		}

		return $tag;
	}

	public function setTag(string $name, Tag $tag) : self {
		$this->{$name} = $tag;
		return $this;
	}

	public function removeTag(string ...$names){
		foreach ($names as $name) {
			unset($this->{$name});
		}
	}

	private function getTagValue(string $name, string $expectedClass, $default = null){
		$tag = $this->getTag($name);

		if($tag instanceof $expectedClass){
			return $tag->getValue();
		}

		if($tag !== null){
			throw new RuntimeException("Expected a tag of type $expectedClass, got " . get_class($tag));
		}

		if($default === null){
			throw new RuntimeException("Tag \"$name\" does not exist");
		}

		return $default;
	}

	public function getByte(string $name, int $default = null) : int{
		return $this->getTagValue($name, ByteTag::class, $default);
	}

	public function getShort(string $name, int $default = null) : int{
		return $this->getTagValue($name, ShortTag::class, $default);
	}

	public function getInt(string $name, int $default = null) : int{
		return $this->getTagValue($name, IntTag::class, $default);
	}

	public function getLong(string $name, int $default = null) : int{
		return $this->getTagValue($name, LongTag::class, $default);
	}

	public function getFloat(string $name, float $default = null) : float{
		return $this->getTagValue($name, FloatTag::class, $default);
	}

	public function getDouble(string $name, float $default = null) : float{
		return $this->getTagValue($name, DoubleTag::class, $default);
	}

	public function getByteArray(string $name, string $default = null) : string{
		return $this->getTagValue($name, ByteArrayTag::class, $default);
	}

	public function getString(string $name, string $default = null) : string{
		return $this->getTagValue($name, StringTag::class, $default);
	}

	/**
	 * @param int[]|null $default
	 *
	 * @return int[]
	 */
	public function getIntArray(string $name, array $default = null) : array{
		return $this->getTagValue($name, IntArrayTag::class, $default);
	}

	/**
	 * @return $this
	 */
	public function setByte(string $name, int $value) : self{
		return $this->setTag($name, new ByteTag($value));
	}

	/**
	 * @return $this
	 */
	public function setShort(string $name, int $value) : self{
		return $this->setTag($name, new ShortTag($value));
	}

	/**
	 * @return $this
	 */
	public function setInt(string $name, int $value) : self{
		return $this->setTag($name, new IntTag($value));
	}

	/**
	 * @return $this
	 */
	public function setLong(string $name, int $value) : self{
		return $this->setTag($name, new LongTag($value));
	}

	/**
	 * @return $this
	 */
	public function setFloat(string $name, float $value) : self{
		return $this->setTag($name, new FloatTag($value));
	}

	/**
	 * @return $this
	 */
	public function setDouble(string $name, float $value) : self{
		return $this->setTag($name, new DoubleTag($value));
	}

	/**
	 * @return $this
	 */
	public function setByteArray(string $name, string $value) : self{
		return $this->setTag($name, new ByteArrayTag($value));
	}

	/**
	 * @return $this
	 */
	public function setString(string $name, string $value) : self{
		return $this->setTag($name, new StringTag($value));
	}

	/**
	 * @param int[] $value
	 *
	 * @return $this
	 */
	public function setIntArray(string $name, array $value) : self{
		return $this->setTag($name, new IntArrayTag($value));
	}

	public function offsetExists($offset){
		return isset($this->{$offset}) && $this->{$offset} instanceof Tag;
	}

	public function offsetGet($offset){
		if(isset($this->{$offset}) && $this->{$offset} instanceof Tag){
			if($this->{$offset} instanceof \ArrayAccess){
				return $this->{$offset};
			}else{
				return $this->{$offset}->getValue();
			}
		}

		return null;
	}

	public function offsetSet($offset, $value){
		if($value instanceof Tag){
			$this->{$offset} = $value;
		}elseif(isset($this->{$offset}) && $this->{$offset} instanceof Tag){
			$this->{$offset}->setValue($value);
		}
	}

	public function offsetUnset($offset){
		unset($this->{$offset});
	}

	public function getType(){
		return NBT::TAG_Compound;
	}

	public function read(NBT $nbt){
		$this->value = [];
		do{
			$tag = $nbt->readTag();
			if($tag instanceof NamedTag && $tag->getName() !== ""){
				$this->{$tag->getName()} = $tag;
			}
		}while(!($tag instanceof EndTag) && !$nbt->feof());
	}

	public function write(NBT $nbt){
		foreach($this as $tag){
			if($tag instanceof Tag && !($tag instanceof EndTag)){
				$nbt->writeTag($tag);
			}
		}
		$nbt->writeTag(new EndTag());
	}

	public function __toString(){
		$str = get_class($this) . "{\n";
		foreach($this as $tag){
			if($tag instanceof Tag){
				$str .= get_class($tag) . ":" . $tag->__toString() . "\n";
			}
		}
		return $str . "}";
	}
}

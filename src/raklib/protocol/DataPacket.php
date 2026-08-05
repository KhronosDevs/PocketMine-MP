<?php



/*
 * RakLib network library
 *
 *
 * This project is not affiliated with Jenkins Software LLC nor RakNet.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 */

declare(strict_types=1);

namespace raklib\protocol;



use function strlen;
use function substr;

abstract class DataPacket extends Packet{

	/** @var (EncapsulatedPacket|string)[] */
	public array $packets = [];

	public ?int $seqNumber = null;

	public function encode() : void{
		parent::encode();
		$this->putLTriad($this->seqNumber ?? 0); //null seqNumber (e.g. after clean()) encodes as 0, matching original weak-mode behavior
		foreach($this->packets as $packet){
			$this->put($packet instanceof EncapsulatedPacket ? $packet->toBinary() : (string) $packet);
		}
	}

	public function length() : int{
		$length = 4;
		foreach($this->packets as $packet){
			$length += $packet instanceof EncapsulatedPacket ? $packet->getTotalLength() : strlen($packet);
		}

		return $length;
	}

	public function decode() : void{
		parent::decode();
		$this->seqNumber = $this->getLTriad();

		while(!$this->feof()){
			$offset = 0;
			$data = substr($this->buffer, $this->offset);
			$packet = EncapsulatedPacket::fromBinary($data, false, $offset);
			$this->offset += $offset;
			if(strlen($packet->buffer) === 0){
				break;
			}
			$this->packets[] = $packet;
		}
	}

	public function clean() : static{
		$this->packets = [];
		$this->seqNumber = null;
		return parent::clean();
	}
}

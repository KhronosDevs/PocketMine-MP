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



use raklib\Binary;
use function count;
use function sort;
use const SORT_NUMERIC;

abstract class AcknowledgePacket extends Packet{
	/** @var int[] */
	public array $packets = [];

	public function encode() : void{
		parent::encode();
		$payload = "";
		sort($this->packets, SORT_NUMERIC);
		$count = count($this->packets);
		$records = 0;

		if($count > 0){
			$pointer = 1;
			$start = $this->packets[0];
			$last = $this->packets[0];

			while($pointer < $count){
				$current = $this->packets[$pointer++];
				$diff = $current - $last;
				if($diff === 1){
					$last = $current;
				}elseif($diff > 1){ //Forget about duplicated packets (bad queues?)
					if($start === $last){
						$payload .= "\x01";
						$payload .= Binary::writeLTriad($start ?? 0); //?? 0: null seq numbers encode as 0 like original weak mode
						$start = $last = $current;
					}else{
						$payload .= "\x00";
						$payload .= Binary::writeLTriad($start ?? 0);
						$payload .= Binary::writeLTriad($last ?? 0);
						$start = $last = $current;
					}
					++$records;
				}
			}

			if($start === $last){
				$payload .= "\x01";
				$payload .= Binary::writeLTriad($start ?? 0);
			}else{
				$payload .= "\x00";
				$payload .= Binary::writeLTriad($start ?? 0);
				$payload .= Binary::writeLTriad($last ?? 0);
			}
			++$records;
		}

		$this->putShort($records);
		$this->buffer .= $payload;
	}

	public function decode() : void{
		parent::decode();
		$count = $this->getShort();
		$this->packets = [];
		$cnt = 0;
		for($i = 0; $i < $count && !$this->feof() && $cnt < 4096; ++$i){
			if($this->getByte() === 0){
				$start = (int) $this->getLTriad();
				$end = (int) $this->getLTriad();
				if($end < $start){
					// Malformed record (end before start). The old code
					// appended (end - start) zero ids here — up to 4096
					// garbage entries on a truncated read — before Session's
					// isset() probes discarded every one of them. Dropping
					// the record is the same wire outcome with none of the
					// blowup (a zlib-bomb NACK could force that allocation).
					continue;
				}
				if(($end - $start) > 512){
					$end = $start + 512;
				}
				for($c = $start; $c <= $end && $cnt < 4096; ++$c){
					$this->packets[$cnt++] = $c;
				}
			}else{
				$this->packets[$cnt++] = $this->getLTriad();
			}
		}
	}

	public function clean() : static{
		$this->packets = [];
		return parent::clean();
	}
}

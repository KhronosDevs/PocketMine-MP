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

namespace raklib\server;



use raklib\protocol\ACK;
use raklib\protocol\CLIENT_CONNECT_DataPacket;
use raklib\protocol\CLIENT_DISCONNECT_DataPacket;
use raklib\protocol\CLIENT_HANDSHAKE_DataPacket;
use raklib\protocol\DATA_PACKET_4;
use raklib\protocol\DataPacket;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\NACK;
use raklib\protocol\OPEN_CONNECTION_REPLY_1;
use raklib\protocol\OPEN_CONNECTION_REPLY_2;
use raklib\protocol\OPEN_CONNECTION_REQUEST_1;
use raklib\protocol\OPEN_CONNECTION_REQUEST_2;
use raklib\protocol\Packet;
use raklib\protocol\PacketReliability;
use raklib\protocol\PING_DataPacket;
use raklib\protocol\PONG_DataPacket;
use raklib\protocol\SERVER_HANDSHAKE_DataPacket;
use raklib\RakLib;
use function abs;
use function array_shift;
use function array_sum;
use function bcadd;
use function count;
use function intval;
use function microtime;
use function min;
use function ord;
use function str_split;
use function strlen;
use function strval;

class Session{
	const STATE_UNCONNECTED = 0;
	const STATE_CONNECTING_1 = 1;
	const STATE_CONNECTING_2 = 2;
	const STATE_CONNECTED = 3;

	const MAX_SPLIT_SIZE = 128;
	const MAX_SPLIT_COUNT = 4;

	public static int $WINDOW_SIZE = 2048;

	private int $messageIndex = 0;
	/** @var int[] */
	private array $channelIndex = [];

	/** @var SessionManager */
	private ?SessionManager $sessionManager;
	private string $address;
	private int $port;
	private int $state = self::STATE_UNCONNECTED;
	private int $mtuSize = 508; //(Max IP Header Size) — (UDP Header Size) = 576 — 60 — 8 = 508
	private int $id = 0;
	private int $splitID = 0;

	private int $sendSeqNumber = 0;
	private int $lastSeqNumber = -1;

	private float $lastUpdate;
	private float $startTime;

	private bool $isTemporal = true;

	/** @var DataPacket[] */
	private array $packetToSend = [];

	private bool $isActive;

	/** @var int[] */
	private array $ACKQueue = [];
	/** @var int[] */
	private array $NACKQueue = [];

	/** @var DataPacket[] */
	private array $recoveryQueue = [];

	/** @var EncapsulatedPacket[][] */
	private array $splitPackets = [];

	/** @var int[][] */
	private array $needACK = [];

	/** @var DataPacket */
	private DataPacket $sendQueue;

	private int $windowStart;
	/** @var int[] */
	private array $receivedWindow = [];
	private int $windowEnd;
	/** @var float[] */
	private array $pingAverage = [0.025];

	private int $reliableWindowStart;
	private int $reliableWindowEnd;
	/** @var EncapsulatedPacket[] */
	private array $reliableWindow = [];
	private int $lastReliableIndex = -1;

	public function __construct(SessionManager $sessionManager, string $address, int $port){
		$this->sessionManager = $sessionManager;
		$this->address = $address;
		$this->port = $port;
		$this->sendQueue = new DATA_PACKET_4();
		$this->lastUpdate = microtime(true);
		$this->startTime = microtime(true);
		$this->isActive = false;
		$this->windowStart = -1;
		$this->windowEnd = self::$WINDOW_SIZE;

		$this->reliableWindowStart = 0;
		$this->reliableWindowEnd = self::$WINDOW_SIZE;

		for($i = 0; $i < 32; ++$i){
			$this->channelIndex[$i] = 0;
		}
	}

	public function getAddress() : string{
		return $this->address;
	}

	public function getPort() : int{
		return $this->port;
	}    public function getID() : int{
        return $this->id;
    }

    /**
     * Trace one encapsulated payload id (only while KHRONOS_WIRE_TRACE=1).
     * The handshake payloads (CLIENT_CONNECT 0x09, CLIENT_HANDSHAKE 0x13)
     * and every connected-phase game payload id are logged with the session
     * state so a stalled real-client flow can be read from the log alone.
     */
    private function trace(string $stateName, int $id, int $len) : void{
        if($this->sessionManager !== null && $this->sessionManager->isWireTrace()){
            $this->sessionManager->getLogger()->debug(
                'enc ' . $this->address . ':' . $this->port . ' state=' . $stateName
                . ' pid=0x' . str_pad(dechex($id), 2, '0', STR_PAD_LEFT) . ' len=' . $len
            );
        }
    }

	public function update(float $time) : void{
		if(!$this->isActive && ($this->lastUpdate + 10) < $time){
			$this->disconnect("timeout");

			return;
		}
		$this->isActive = false;

		if(count($this->ACKQueue) > 0){
			$pk = new ACK();
			$pk->packets = $this->ACKQueue;
			$this->sendPacket($pk);
			$this->ACKQueue = [];
		}

		if(count($this->NACKQueue) > 0){
			$pk = new NACK();
			$pk->packets = $this->NACKQueue;
			$this->sendPacket($pk);
			$this->NACKQueue = [];
		}

		if(count($this->packetToSend) > 0){
			$limit = 16;
			foreach($this->packetToSend as $k => $pk){
				$this->sendDatagram($pk);
				$this->recoveryQueue[$pk->seqNumber] = $pk;
				unset($this->packetToSend[$k]);

				if(--$limit <= 0){
					break;
				}
			}

			if(count($this->packetToSend) > self::$WINDOW_SIZE){
				if($this->sessionManager !== null){
					$this->sessionManager->getLogger()->critical(
						'Recovery queue overflow for ' . $this->address . ':' . $this->port
						. ' — dropped ' . count($this->packetToSend) . ' pending retransmissions'
					);
				}
				$this->packetToSend = [];
			}
		}

		if(count($this->needACK) > 0){
			foreach($this->needACK as $identifierACK => $indexes){
				if(count($indexes) === 0){
					unset($this->needACK[$identifierACK]);
					$this->sessionManager->notifyACK($this, $identifierACK);
				}
			}
		}

		foreach($this->recoveryQueue as $seq => $pk){
			// Compare against $time (microtime resolution, passed in by the
			// SessionManager tick) instead of time(): sendTime is microtime, so
			// the old 1-second-resolution comparison could retransmit up to a
			// second late and jittered with the wall-clock second boundary.
			if($pk->sendTime < ($time - 8)){
				$this->packetToSend[] = $pk;
				unset($this->recoveryQueue[$seq]);
			}else{
				break;
			}
		}

		foreach($this->receivedWindow as $seq => $bool){
			if($seq < $this->windowStart){
				unset($this->receivedWindow[$seq]);
			}else{
				break;
			}
		}

		$this->sendQueue();
	}

	public function disconnect(string $reason = "unknown") : void{
		$this->sessionManager->removeSession($this, $reason);
	}

	private function sendDatagram(DataPacket $datagram) : void{
		if($datagram->seqNumber !== null){
			unset($this->recoveryQueue[$datagram->seqNumber]);
		}
		$datagram->seqNumber = $this->sendSeqNumber++;
		$datagram->sendTime = microtime(true);
		$this->recoveryQueue[$datagram->seqNumber] = $datagram;
		$this->sendPacket($datagram);
	}

	private function sendPacket(Packet $packet) : void{
		$this->sessionManager->sendPacket($packet, $this->address, $this->port);
	}

	public function sendQueue() : void{
		if(count($this->sendQueue->packets) > 0){
			$this->sendDatagram($this->sendQueue);
			$this->sendQueue = new DATA_PACKET_4();
		}
	}

	private function addToQueue(EncapsulatedPacket $pk, int $flags = RakLib::PRIORITY_NORMAL) : void{
		$priority = $flags & 0b00000111;
		if($pk->needACK && $pk->messageIndex !== null){
			$this->needACK[$pk->identifierACK][$pk->messageIndex] = $pk->messageIndex;
		}

		$length = $this->sendQueue->length();
		if($length + $pk->getTotalLength() > $this->mtuSize - 36){ //IP header (20 bytes) + UDP header (8 bytes) + RakNet weird (8 bytes) = 36 bytes
			$this->sendQueue();
		}

		if($pk->needACK){
			$this->sendQueue->packets[] = clone $pk;
			$pk->needACK = false;
		}else{
			$this->sendQueue->packets[] = $pk->toBinary();
		}

		if($priority === RakLib::PRIORITY_IMMEDIATE){
			// Forces pending sends to go out now, rather than waiting to the next update interval
			$this->sendQueue();
		}
	}    public function addEncapsulatedToQueue(EncapsulatedPacket $packet, int $flags = RakLib::PRIORITY_NORMAL) : void{

		if(($packet->needACK = ($flags & RakLib::FLAG_NEED_ACK) > 0) === true){
			$this->needACK[$packet->identifierACK] = [];
		}

		if(
			$packet->reliability === PacketReliability::RELIABLE ||
			$packet->reliability === PacketReliability::RELIABLE_ORDERED ||
			$packet->reliability === PacketReliability::RELIABLE_SEQUENCED ||
			$packet->reliability === PacketReliability::RELIABLE_WITH_ACK_RECEIPT ||
			$packet->reliability === PacketReliability::RELIABLE_ORDERED_WITH_ACK_RECEIPT
		){
			$packet->messageIndex = $this->messageIndex++;

			if($packet->reliability === PacketReliability::RELIABLE_ORDERED){
				$packet->orderIndex = $this->channelIndex[$packet->orderChannel]++; //TODO: maybe a generic order channel?
			}
		}

		$maxSize = $this->mtuSize - 60;

		if(strlen($packet->buffer) > $maxSize){
			$buffers = str_split($packet->buffer, $maxSize);
			$bufferCount = count($buffers);            $splitID = ++$this->splitID % 65536;
			foreach($buffers as $count => $buffer){
				$pk = new EncapsulatedPacket();
				$pk->splitID = $splitID;
				$pk->hasSplit = true;
				$pk->splitCount = $bufferCount;
				$pk->reliability = $packet->reliability;
				$pk->splitIndex = $count;
				$pk->buffer = $buffer;
				if($count > 0){
					$pk->messageIndex = $this->messageIndex++;
				}else{
					$pk->messageIndex = $packet->messageIndex;
				}
				if($pk->reliability === PacketReliability::RELIABLE_ORDERED){
					$pk->orderChannel = $packet->orderChannel;
					$pk->orderIndex = $packet->orderIndex;
				}
				$this->addToQueue($pk, $flags | RakLib::PRIORITY_IMMEDIATE);
			}
		}else{
			$this->addToQueue($packet, $flags);
		}
	}

	private function handleSplit(EncapsulatedPacket $packet) : void{
		if($packet->splitCount >= self::MAX_SPLIT_SIZE || $packet->splitIndex >= self::MAX_SPLIT_SIZE || $packet->splitIndex < 0){
			return;
		}

		if(!isset($this->splitPackets[$packet->splitID])){
			if(count($this->splitPackets) >= self::MAX_SPLIT_COUNT){
				return;
			}
			$this->splitPackets[$packet->splitID] = [$packet->splitIndex => $packet];
		}else{
			$this->splitPackets[$packet->splitID][$packet->splitIndex] = $packet;
		}

		if(count($this->splitPackets[$packet->splitID]) === $packet->splitCount){
			$pk = new EncapsulatedPacket();
			$pk->buffer = "";
			for($i = 0; $i < $packet->splitCount; ++$i){
				$pk->buffer .= $this->splitPackets[$packet->splitID][$i]->buffer;
			}

			$pk->length = strlen($pk->buffer);
			unset($this->splitPackets[$packet->splitID]);

			$this->handleEncapsulatedPacketRoute($pk);
		}
	}

	private function handleEncapsulatedPacket(EncapsulatedPacket $packet) : void{
		if($packet->messageIndex === null){
			$this->handleEncapsulatedPacketRoute($packet);
		}else{
			if($packet->messageIndex < $this->reliableWindowStart || $packet->messageIndex > $this->reliableWindowEnd){
				return;
			}

			if(($packet->messageIndex - $this->lastReliableIndex) === 1){
				$this->lastReliableIndex++;
				$this->reliableWindowStart++;
				$this->reliableWindowEnd++;
				$this->handleEncapsulatedPacketRoute($packet);

				// Drain the consecutive buffered messages that follow. A keyed
				// isset probe replaces the old ksort()+foreach walk: both deliver
				// the same leading run in ascending order and stop at the first
				// gap, but the probe is O(1) per packet instead of an O(m log m)
				// sort per drain (~5x at 500 buffered messages under reordering).
				while(isset($this->reliableWindow[$this->lastReliableIndex + 1])){
					$next = $this->reliableWindow[$this->lastReliableIndex + 1];
					unset($this->reliableWindow[$this->lastReliableIndex + 1]);
					$this->lastReliableIndex++;
					$this->reliableWindowStart++;
					$this->reliableWindowEnd++;
					$this->handleEncapsulatedPacketRoute($next);
				}
			}else{
				$this->reliableWindow[$packet->messageIndex] = $packet;
			}
		}

	}

	public function getState() : int{
		return $this->state;
	}

	public function isTemporal() : bool{
		return $this->isTemporal;
	}    private function handleEncapsulatedPacketRoute(EncapsulatedPacket $packet) : void{
		if($this->sessionManager === null){
			return;
		}

		if($packet->hasSplit){
			if($this->state === self::STATE_CONNECTED){
				$this->handleSplit($packet);
			}
			return;
		}        $id = ord($packet->buffer[0]);
        $this->trace(match ($this->state) {
            self::STATE_CONNECTING_2 => 'connecting2',
            self::STATE_CONNECTED => 'connected',
            default => 'preconnect',
        }, $id, strlen($packet->buffer));

		// Connected sessions: every payload except the RakNet control packets
		// is game data and goes straight to the main thread. Note that the
		// MCPE protocol-84 batch packet id (0x06) is BELOW 0x80 - the old
		// `$id >= 0x80` guard silently swallowed it, which is why the legacy
		// code appeared to drop game traffic. (TODO: stream channels)
		if($this->state === self::STATE_CONNECTED){
			if($id === CLIENT_DISCONNECT_DataPacket::$ID){
				$this->disconnect("client disconnect");
			}elseif($id === PING_DataPacket::$ID){
				$dataPacket = new PING_DataPacket;
				$dataPacket->buffer = $packet->buffer;
				$dataPacket->decode();

				$pk = new PONG_DataPacket;
				$pk->pingID = $dataPacket->pingID;
				$pk->encode();

				$sendPacket = new EncapsulatedPacket();
				$sendPacket->reliability = PacketReliability::UNRELIABLE;
				$sendPacket->buffer = $pk->buffer;
				$this->addToQueue($sendPacket);
			}else{
				$this->sessionManager->streamEncapsulated($this, $packet);
			}
			return;
		}

		// Handshake state (CONNECTING_2): RakNet internal control packets only.
		if($this->state === self::STATE_CONNECTING_2){
			if($id === CLIENT_CONNECT_DataPacket::$ID){
				$dataPacket = new CLIENT_CONNECT_DataPacket;
				$dataPacket->buffer = $packet->buffer;
				$dataPacket->decode();
				$pk = new SERVER_HANDSHAKE_DataPacket;
				$pk->address = $this->address;
				$pk->port = $this->port;
				$pk->sendPing = $dataPacket->sendPing;
				$pk->sendPong = (int) bcadd(strval($pk->sendPing), "100");
				$pk->encode();

				$sendPacket = new EncapsulatedPacket();
				$sendPacket->reliability = PacketReliability::UNRELIABLE;
				$sendPacket->buffer = $pk->buffer;
				$this->addToQueue($sendPacket, RakLib::PRIORITY_IMMEDIATE);
			}elseif($id === CLIENT_HANDSHAKE_DataPacket::$ID){
				$dataPacket = new CLIENT_HANDSHAKE_DataPacket;
				$dataPacket->buffer = $packet->buffer;
				$dataPacket->decode();

				if($dataPacket->port === $this->sessionManager->getPort() || !$this->sessionManager->portChecking){
					$this->state = self::STATE_CONNECTED; //FINALLY!
					$this->isTemporal = false;
					$this->sessionManager->openSession($this);
				}
			}
		}
	}

	public function handlePacket(Packet $packet) : void{
		$this->isActive = true;
		$this->lastUpdate = microtime(true);
		if($this->state === self::STATE_CONNECTED || $this->state === self::STATE_CONNECTING_2){
			if($packet::$ID >= 0x80 && $packet::$ID <= 0x8f && $packet instanceof DataPacket){ //Data packet
				$packet->decode();

				if($packet->seqNumber < $this->windowStart || $packet->seqNumber > $this->windowEnd || isset($this->receivedWindow[$packet->seqNumber])){
					return;
				}

				$diff = $packet->seqNumber - $this->lastSeqNumber;

				unset($this->NACKQueue[$packet->seqNumber]);
				$this->ACKQueue[$packet->seqNumber] = $packet->seqNumber;
				$this->receivedWindow[$packet->seqNumber] = $packet->seqNumber;

				if($diff !== 1){
					for($i = $this->lastSeqNumber + 1; $i < $packet->seqNumber; ++$i){
						if(!isset($this->receivedWindow[$i])){
							$this->NACKQueue[$i] = $i;
						}
					}
				}

				if($diff >= 1){
					$this->lastSeqNumber = $packet->seqNumber;
					$this->windowStart += $diff;
					$this->windowEnd += $diff;
				}

				foreach($packet->packets as $pk){
					$this->handleEncapsulatedPacket($pk);
				}
			}else{
				if($packet instanceof ACK){
					$packet->decode();
					foreach($packet->packets as $seq){
						if(isset($this->recoveryQueue[$seq])){
							$this->pingAverage[] = microtime(true) - $this->recoveryQueue[$seq]->sendTime;

							if(count($this->pingAverage) > 20){
								array_shift($this->pingAverage);
							}

							foreach($this->recoveryQueue[$seq]->packets as $pk){
								if($pk instanceof EncapsulatedPacket && $pk->needACK && $pk->messageIndex !== null){
									unset($this->needACK[$pk->identifierACK][$pk->messageIndex]);
								}
							}
							unset($this->recoveryQueue[$seq]);
						}
					}
				}elseif($packet instanceof NACK){
					$packet->decode();
					foreach($packet->packets as $seq){
						if(isset($this->recoveryQueue[$seq])){
							$this->packetToSend[] = $this->recoveryQueue[$seq];
							unset($this->recoveryQueue[$seq]);
						}
					}
				}
			}

		}elseif($packet::$ID > 0x00 && $packet::$ID < 0x80){ //Not Data packet :)
			$packet->decode();
			if($packet instanceof OPEN_CONNECTION_REQUEST_1){
				$packet->protocol; //TODO: check protocol number and refuse connections
				$pk = new OPEN_CONNECTION_REPLY_1();
				$pk->mtuSize = $packet->mtuSize;
				$pk->serverID = $this->sessionManager->getID();
				$this->sendPacket($pk);
				$this->state = self::STATE_CONNECTING_1;
			}elseif($this->state === self::STATE_CONNECTING_1 && $packet instanceof OPEN_CONNECTION_REQUEST_2){
				$this->id = $packet->clientID;
				if($packet->serverPort === $this->sessionManager->getPort() || !$this->sessionManager->portChecking){
					$this->mtuSize = min(abs($packet->mtuSize), 1432); //Max size, do not allow creating large buffers to fill server memory
					$pk = new OPEN_CONNECTION_REPLY_2();
					$pk->mtuSize = $this->mtuSize;
					$pk->serverID = $this->sessionManager->getID();
					$pk->clientAddress = $this->address;
					$pk->clientPort = $this->port;
					$this->sendPacket($pk);
					$this->state = self::STATE_CONNECTING_2;
				}
			}
		}
	}

	public function getPing() : int{
		return intval((array_sum($this->pingAverage) / count($this->pingAverage)) * 1000);
	}

	public function close() : void{
		$data = "\x60\x00\x08\x00\x00\x00\x00\x00\x00\x00\x00\x15";
		$this->addEncapsulatedToQueue(EncapsulatedPacket::fromBinary($data)); //CLIENT_DISCONNECT packet 0x15
		// Flush the send queue so the queued disconnect frames (a game-level
		// DisconnectPacket queued just before close, plus this CLIENT_DISCONNECT
		// control) actually reach the client. Without this the session is
		// dropped silently and the client only notices at its own disconnect
		// timeout - a kick/ban feels delayed instead of immediate.
		$this->sendQueue();
		$this->sessionManager = null;
	}
}

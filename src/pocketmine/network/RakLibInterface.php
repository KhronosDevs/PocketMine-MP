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

namespace pocketmine\network;



use pocketmine\event\player\PlayerCreationEvent;
use pocketmine\network\protocol\DataPacket;
use pocketmine\network\protocol\Info as ProtocolInfo;
use pocketmine\Player;
use pocketmine\Server;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\PacketReliability;
use raklib\RakLib;
use raklib\server\RakLibServer;
use raklib\server\ServerHandler;
use raklib\server\ServerInstance;
use function addcslashes;
use function bin2hex;
use function chr;
use function count;
use function get_class;
use function ord;
use function rtrim;
use function spl_object_hash;
use function strlen;
use function time;
use function unserialize;

class RakLibInterface implements ServerInstance, AdvancedSourceInterface{

	/** @var Server */
	private Server $server;

	/** @var Network */
	private Network $network;

	/** @var RakLibServer */
	private RakLibServer $rakLib;

	/** @var Player[] */
	private array $players = [];

	/** @var string[] */
	private array $identifiers = [];

	/** @var int[] */
	private array $identifiersACK = [];

	/** @var ServerHandler */
	private ServerHandler $interface;

	public function __construct(Server $server){

		$this->server = $server;
		$this->identifiers = [];

		$this->rakLib = new RakLibServer($this->server->getLogger(), $this->server->getLoader(), $this->server->getPort(), $this->server->getIp() === "" ? "0.0.0.0" : $this->server->getIp());
		$this->interface = new ServerHandler($this->rakLib, $this);
	}

	public function setNetwork(Network $network) : void{
		$this->network = $network;
	}

	public function process() : bool{
		$work = false;
		if($this->interface->handlePacket()){
			$work = true;
			$lasttime = time();
			while($this->interface->handlePacket()){
				$diff = time() - $lasttime;
				if($diff >= 1) break;
			}
		}

		if($this->rakLib->isTerminated()){
			$this->network->unregisterInterface($this);

			throw new \Exception("RakLib Thread crashed");
		}

		return $work;
	}

	public function closeSession(string $identifier, string $reason) : void{
		if(isset($this->players[$identifier])){
			$player = $this->players[$identifier];
			unset($this->identifiers[spl_object_hash($player)]);
			unset($this->players[$identifier]);
			unset($this->identifiersACK[$identifier]);
			$player->close($player->getLeaveMessage(), $reason);
		}
	}

	public function close(Player $player, string $reason = "unknown reason") : void{
		if(isset($this->identifiers[$h = spl_object_hash($player)])){
			unset($this->players[$this->identifiers[$h]]);
			unset($this->identifiersACK[$this->identifiers[$h]]);
			$this->interface->closeSession($this->identifiers[$h], $reason);
			unset($this->identifiers[$h]);
		}
	}

	public function shutdown() : void{
		$this->interface->shutdown();
	}

	public function emergencyShutdown() : void{
		$this->interface->emergencyShutdown();
	}

	public function openSession(string $identifier, string $address, int $port, $clientID) : void{
		$ev = new PlayerCreationEvent($this, Player::class, Player::class, null, $address, $port);
		$this->server->getPluginManager()->callEvent($ev);
		$class = $ev->getPlayerClass();

		$player = new $class($this, $ev->getClientId(), $ev->getAddress(), $ev->getPort());
		$this->players[$identifier] = $player;
		$this->identifiersACK[$identifier] = 0;
		$this->identifiers[spl_object_hash($player)] = $identifier;
		$this->server->addPlayer($identifier, $player);
	}

	public function handleEncapsulated(string $identifier, EncapsulatedPacket $packet, int $flags) : void{
		if(isset($this->players[$identifier])){
			try{
				if($packet->buffer !== ""){
					$pk = $this->getPacket($packet->buffer);
					if($pk !== null){
						$pk->decode();
						$this->players[$identifier]->handleDataPacket($pk);
					}
				}
			}catch(\Throwable $e){
				$logger = $this->server->getLogger();
				if(\pocketmine\DEBUG > 1 && isset($pk)){
					$logger->debug("Exception in packet " . get_class($pk) . " 0x" . bin2hex($packet->buffer));
				}
				$logger->logException($e);
			}
		}
	}

	public function blockAddress(string $address, int $timeout = 300) : void{
		$this->interface->blockAddress($address, $timeout);
	}

	public function unblockAddress(string $address) : void{
		$this->interface->unblockAddress($address);
	}

	public function handleRaw(string $address, int $port, string $payload) : void{
		$this->server->getNetwork()->handlePacket($address, $port, $payload);
	}

	public function sendRawPacket(string $address, int $port, string $payload) : void{
		$this->interface->sendRaw($address, $port, $payload);
	}

	public function notifyACK(string $identifier, int $identifierACK) : void{

	}

	public function handlePing(string $identifier, int $ping) : void{
		if(!isset($this->players[$identifier])) return;

		$this->players[$identifier]->setPing($ping);
	}

	public function setName(string $name) : void{
		$name .= "";

		if($this->server->isDServerEnabled()){
			if($this->server->dserverConfig["motdMaxPlayers"] > 0) $pc = $this->server->dserverConfig["motdMaxPlayers"];
			elseif($this->server->dserverConfig["motdAllPlayers"]) $pc = $this->server->getDServerMaxPlayers();
			else $pc = $this->server->getMaxPlayers();

			if($this->server->dserverConfig["motdPlayers"]) $poc = $this->server->getDServerOnlinePlayers();
			else $poc = count($this->server->getOnlinePlayers());
		}else{
			$info = $this->server->getQueryInformation();
			$pc = $info->getMaxPlayerCount();
			$poc = $info->getPlayerCount();
		}

		$this->interface->sendOption("name",
			"MCPE;" . rtrim(addcslashes($name, ";"), '\\') . ";" .
			ProtocolInfo::CURRENT_PROTOCOL . ";" .
			/*VERSION*/ ";" .
			$poc . ";" .
			$pc
		);
	}

	public function setPortCheck(bool $name) : void{
		$this->interface->sendOption("portChecking", $name ? "1" : "");
	}

	public function handleOption(string $name, string $value) : void{
		if($name === "bandwidth"){
			$v = unserialize($value);
			$this->network->addStatistics($v["up"], $v["down"]);
		}
	}

	public function putPacket(Player $player, DataPacket $packet, bool $needACK = false, bool $immediate = false) : ?int{
		if(isset($this->identifiers[$h = spl_object_hash($player)])){
			$identifier = $this->identifiers[$h];
			$pk = null;
			if(!$packet->isEncoded){
				$packet->encode();
				$packet->isEncoded = true;
			}elseif(!$needACK){
				if(!isset($packet->__encapsulatedPacket)){
					$packet->__encapsulatedPacket = new CachedEncapsulatedPacket;
					$packet->__encapsulatedPacket->identifierACK = null;
					$packet->__encapsulatedPacket->buffer = chr(0xfe) . $packet->buffer;
					$packet->__encapsulatedPacket->reliability = PacketReliability::RELIABLE_ORDERED;
					$packet->__encapsulatedPacket->orderChannel = 0;
				}
				$pk = $packet->__encapsulatedPacket;
			}

			if(!$immediate && !$needACK && $packet::NETWORK_ID !== ProtocolInfo::BATCH_PACKET
				&& Network::$BATCH_THRESHOLD >= 0
				&& strlen($packet->buffer) >= Network::$BATCH_THRESHOLD){
				$this->server->batchPackets([$player], [$packet], true);
				return null;
			}

			if($pk === null){
				$pk = new EncapsulatedPacket();
				$pk->buffer = chr(0xfe) . $packet->buffer;
				$packet->reliability = PacketReliability::RELIABLE_ORDERED;
				$packet->orderChannel = 0;

				if($needACK === true){
					$pk->identifierACK = $this->identifiersACK[$identifier]++;
				}
			}

			$this->interface->sendEncapsulated($identifier, $pk, ($needACK === true ? RakLib::FLAG_NEED_ACK : 0) | ($immediate === true ? RakLib::PRIORITY_IMMEDIATE : RakLib::PRIORITY_NORMAL));

			return $pk->identifierACK;
		}

		return null;
	}

	private function getPacket(string $buffer) : ?DataPacket{
		$pid = ord($buffer[0]);
		$start = 1;
		if($pid == 0xfe){
			$pid = ord($buffer[1]);
			$start++;
		}
		if(($data = $this->network->getPacket($pid)) === null){
			return null;
		}
		$data->setBuffer($buffer, $start);

		return $data;
	}
}

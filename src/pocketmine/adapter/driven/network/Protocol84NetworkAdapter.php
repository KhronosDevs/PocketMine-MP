<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\network;

use pocketmine\network\Network;
use pocketmine\network\protocol\DataPacket;
use pocketmine\network\protocol\DisconnectPacket;
use pocketmine\Player;
use pocketmine\port\driven\NetworkPort;
use pocketmine\port\driven\PlayerRef;
use pocketmine\Server;

final class Protocol84NetworkAdapter implements NetworkPort {
    private ?Network $legacyNetwork = null;
    private array $pendingCommands = [];
    private array $outboundPackets = [];

    public function __construct(?Network $legacyNetwork = null) {
        $this->legacyNetwork = $legacyNetwork;
    }

    public function setLegacyNetwork(Network $network): void {
        $this->legacyNetwork = $network;
    }

    public function sendPacket(PlayerRef $player, DataPacket $packet): void {
        $this->outboundPackets[] = [$player, $packet];
    }

    public function broadcastPacket(iterable $players, DataPacket $packet): void {
        foreach ($players as $player) {
            $this->outboundPackets[] = [$player, $packet];
        }
    }

    public function disconnect(PlayerRef $player, string $reason): void {
        $pk = new DisconnectPacket();
        $pk->message = $reason;
        $pk->hideDisconnectionScreen = false;
        $this->sendPacket($player, $pk);
    }

    public function processPendingCommands(): void {
        // Drain commands from network thread (future)
        $this->pendingCommands = [];
    }

    public function flushOutboundPackets(): void {
        if (!$this->legacyNetwork || empty($this->outboundPackets)) {
            return;
        }

        $server = Server::getInstance();
        $playersByEntityId = [];
        foreach ($server->getOnlinePlayers() as $player) {
            $playersByEntityId[$player->getId()] = $player;
        }

        foreach ($this->outboundPackets as [$playerRef, $packet]) {
            if (isset($playersByEntityId[$playerRef->entityId])) {
                $playersByEntityId[$playerRef->entityId]->dataPacket($packet);
            }
        }
        $this->outboundPackets = [];
    }

    public function createPlayerRef(Player $player): PlayerRef {
        return new PlayerRef(
            $player->getRawUniqueId(),
            $player->getId(),
            $player->getName()
        );
    }
}
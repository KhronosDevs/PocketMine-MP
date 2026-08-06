<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\network;

use pocketmine\network\Network;
use pocketmine\network\protocol\DataPacket;
use pocketmine\port\driven\NetworkPort;
use pocketmine\port\driven\PlayerRef;

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
        // TODO: Create DisconnectPacket and send
        $this->pendingCommands[] = ['disconnect', $player, $reason];
    }

    public function processPendingCommands(): void {
        // Drain commands from network thread (future)
        $this->pendingCommands = [];
    }

    public function flushOutboundPackets(): void {
        if (!$this->legacyNetwork || empty($this->outboundPackets)) {
            return;
        }

        foreach ($this->outboundPackets as [$playerRef, $packet]) {
            // TODO: Map PlayerRef to legacy Player and send
            // $legacyPlayer = $this->legacyNetwork->getPlayerById($playerRef->entityId);
            // if ($legacyPlayer) $legacyPlayer->dataPacket($packet);
        }
        $this->outboundPackets = [];
    }
}
<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\network;

use pocketmine\protocol\DataPacket;
use pocketmine\protocol\DisconnectPacket;
use pocketmine\protocol\Info;
use pocketmine\port\driven\NetworkPort;
use pocketmine\port\driven\PlayerRef;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\PacketReliability;
use raklib\server\RakLibServer;
use raklib\server\ServerHandler;
use raklib\server\ServerInstance;
use pmmp\thread\Thread;
use function addcslashes;
use function count;
use function rtrim;

/**
 * Protocol 84 network adapter.
 *
 * Owns a RakLibServer thread which speaks the full RakNet wire protocol
 * (offline + connected handshake, DATA_PACKET framing, reliability windows,
 * ACK/NACK, split reassembly). This class is the ServerInstance: it receives
 * decoded game packets from the thread and forwards them to the session
 * service as scalar events, and it wraps outbound game packets as reliable
 * encapsulated frames for the thread to deliver.
 *
 * The thread owns the UDP socket; there is no main-thread socket I/O.
 */
final class Protocol84NetworkAdapter implements NetworkPort, ServerInstance {
    private ?RakLibServer $rakLibServer = null;
    private ?ServerHandler $serverHandler = null;
    private string $bindAddress = "0.0.0.0";
    private int $bindPort = 19132;
    private bool $running = false;
    private string $serverName = "Khronos Server";
    private int $maxPlayers = 20;
    /** @var array<string, PlayerRef> address:port => PlayerRef */
    private array $connectedPlayers = [];
    /** @var list<array{0: string, 1: int, 2: string}> [addrKey, packetId, buffer] */
    private array $pendingInbound = [];
    /** @var list<array{0: string, 1: string, 2: string}> ['open'|'close', identifier, detail] */
    private array $pendingConnections = [];
    /** @var list<string> log lines drained from the RakLib thread */
    private array $logLines = [];

    public function __construct(string $bindAddress = "0.0.0.0", int $bindPort = 19132) {
        $this->bindAddress = $bindAddress;
        $this->bindPort = $bindPort;
    }

    /**
     * Override the bind port before start(). Only honored while the thread is
     * not yet running; used by tests to avoid clashing with the default port.
     */
    public function setBindPort(int $port): void {
        if ($this->running) {
            return;
        }
        $this->bindPort = $port;
    }

    public function isRunning(): bool {
        return $this->running;
    }

    public function getBindPort(): int {
        return $this->bindPort;
    }

    /** Create and start the RakLibServer thread (binds the UDP socket). */
    public function start(): void {
        if ($this->running) {
            return;
        }
        RakLibServer::preload();
        $this->rakLibServer = new RakLibServer($this->bindPort, $this->bindAddress);
        $this->serverHandler = new ServerHandler($this->rakLibServer, $this);
        // The session manager reads the server name for UNCONNECTED_PONG.
        // Real 0.15.x clients parse a structured motd (MCPE;<name>;<protocol>;
        // <version>;<online>;<max>) and will not list the server otherwise.
        $this->sendMotd();
        $this->rakLibServer->start(Thread::INHERIT_ALL);
        $this->running = true;
    }

    /** Stop the RakLib thread and clear all session bookkeeping. */
    public function shutdown(): void {
        if ($this->running && $this->serverHandler !== null) {
            $this->serverHandler->shutdown();
        }
        $this->rakLibServer = null;
        $this->serverHandler = null;
        $this->connectedPlayers = [];
        $this->pendingInbound = [];
        $this->pendingConnections = [];
        $this->running = false;
    }

    /**
     * Called from the kernel main loop: drain RakLib thread events into the
     * session service, then collect any log lines the thread produced.
     */
    public function processPendingCommands(): void {
        if ($this->serverHandler === null) {
            return;
        }
        while ($this->serverHandler->handlePacket()) {
        }
        if ($this->rakLibServer !== null) {
            while (($line = $this->rakLibServer->getLogQueue()->shift()) !== null) {
                if (is_string($line)) {
                    $this->logLines[] = $line;
                }
            }
        }
    }

    /** Outbound frames are handed to the RakLib thread immediately; nothing to flush. */
    public function flushOutboundPackets(): void {
    }

    // --- NetworkPort -------------------------------------------------------

    public function sendPacket(PlayerRef $player, DataPacket $packet): void {
        $addrKey = $this->findAddressByPlayerRef($player);
        if ($addrKey === null) {
            return;
        }
        $packet->encode();
        $this->sendEncapsulatedBuffer($addrKey, $packet->getBuffer());
    }

    /**
     * Send a packet to a raw address (no PlayerRef registration required).
     * Used for pre-session replies such as login-failed status.
     */
    public function sendRawPacket(string $addrKey, DataPacket $packet): void {
        $packet->encode();
        $this->sendEncapsulatedBuffer($addrKey, $packet->getBuffer());
    }

    /** Bind a logged-in player's address to its PlayerRef for sends. */
    public function registerPlayer(string $addrKey, PlayerRef $playerRef): void {
        $this->connectedPlayers[$addrKey] = $playerRef;
        $this->sendMotd();
    }

    /** Forget a player's address mapping (on disconnect/shutdown). */
    public function unregisterPlayer(PlayerRef $playerRef): void {
        $this->removePlayer($playerRef);
        $this->sendMotd();
    }

    public function broadcastPacket(iterable $players, DataPacket $packet): void {
        foreach ($players as $player) {
            $this->sendPacket($player, $packet);
        }
    }

    public function disconnect(PlayerRef $player, string $reason): void {
        $addrKey = $this->findAddressByPlayerRef($player);
        if ($addrKey !== null) {
            $pk = new DisconnectPacket();
            $pk->message = $reason;
            $pk->hideDisconnectionScreen = false;
            $this->sendPacket($player, $pk);
            if ($this->serverHandler !== null) {
                $this->serverHandler->closeSession($addrKey, $reason);
            }
        }
        $this->removePlayer($player);
    }

    /**
     * Pop decoded inbound game-packet events for the kernel to dispatch.
     * @return list<array{0: string, 1: int, 2: string}>
     */
    public function pollInboundEvents(): array {
        $events = $this->pendingInbound;
        $this->pendingInbound = [];
        return $events;
    }

    /**
     * Pop transport-level session events.
     * @return list<array{0: string, 1: string, 2: string}> ['open'|'close', identifier, detail]
     */
    public function pollConnectionEvents(): array {
        $events = $this->pendingConnections;
        $this->pendingConnections = [];
        return $events;
    }

    /** Drain accumulated RakLib thread log lines (for tests/introspection). */
    public function drainLogLines(): array {
        $lines = $this->logLines;
        $this->logLines = [];
        return $lines;
    }

    // --- ServerInstance (main-thread callbacks from the RakLib thread) -----

    public function openSession(string $identifier, string $address, int $port, int $clientID): void {
        // Transport connected. The game session is created when the login
        // packet arrives; nothing to do here beyond remembering the address.
        $this->pendingConnections[] = ['open', $identifier, (string)$clientID];
    }

    public function closeSession(string $identifier, string $reason): void {
        $this->removePlayerByIdentifier($identifier);
        $this->pendingConnections[] = ['close', $identifier, $reason];
        $this->sendMotd();
    }

    public function handleEncapsulated(string $identifier, EncapsulatedPacket $packet, int $flags): void {
        $buffer = $packet->buffer;
        if ($buffer === '' || strlen($buffer) < 1) {
            return;
        }
        $this->pendingInbound[] = [$identifier, ord($buffer[0]), $buffer];
    }

    public function handleRaw(string $address, int $port, string $payload): void {
        // Unconnected traffic past the offline handshake is answered entirely
        // inside the RakLib thread; nothing to do here.
    }

    public function notifyACK(string $identifier, int $identifierACK): void {
    }

    public function handleOption(string $option, string $value): void {
    }

    public function handlePing(string $identifier, int $ping): void {
    }

    // --- Helpers -----------------------------------------------------------

    private function sendEncapsulatedBuffer(string $identifier, string $buffer): void {
        if ($this->serverHandler === null) {
            return;
        }
        $pk = new EncapsulatedPacket();
        $pk->reliability = PacketReliability::RELIABLE_ORDERED;
        $pk->orderChannel = 0;
        $pk->buffer = $buffer;
        $this->serverHandler->sendEncapsulated($identifier, $pk);
    }

    private function findAddressByPlayerRef(PlayerRef $playerRef): ?string {
        foreach ($this->connectedPlayers as $addrKey => $ref) {
            if ($ref->entityId === $playerRef->entityId) {
                return $addrKey;
            }
        }
        return null;
    }

    private function removePlayer(PlayerRef $player): void {
        foreach ($this->connectedPlayers as $addrKey => $ref) {
            if ($ref->entityId === $player->entityId) {
                unset($this->connectedPlayers[$addrKey]);
                break;
            }
        }
    }

    private function removePlayerByIdentifier(string $identifier): void {
        unset($this->connectedPlayers[$identifier]);
    }

    public function setServerName(string $name): void {
        $this->serverName = $name;
        $this->sendMotd();
    }

    public function setMaxPlayers(int $maxPlayers): void {
        $this->maxPlayers = $maxPlayers;
        $this->sendMotd();
    }

    /**
     * Build and push the RakNet motd a real client parses in UNCONNECTED_PONG:
     * MCPE;<name>;<protocol>;<version>;<online>;<max> (same layout as the
     * legacy RakLibInterface::setName).
     */
    private function sendMotd(): void {
        if ($this->serverHandler === null) {
            return;
        }
        // Same layout as the legacy RakLibInterface::setName (version field
        // left empty there too). A real 0.15.10 client parses these fields.
        $motd = 'MCPE;' . rtrim(addcslashes($this->serverName, ';'), '\\') . ';'
            . Info::CURRENT_PROTOCOL . ';'
            . ';' // version
            . count($this->connectedPlayers) . ';'
            . $this->maxPlayers;
        $this->serverHandler->sendOption('name', $motd);
    }

    public function getConnectedPlayers(): array {
        return array_values($this->connectedPlayers);
    }
}

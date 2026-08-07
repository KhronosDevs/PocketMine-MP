<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\network;

use pocketmine\network\protocol\DataPacket;
use pocketmine\network\protocol\DisconnectPacket;
use pocketmine\network\protocol\Info;
use pocketmine\port\driven\NetworkPort;
use pocketmine\port\driven\PlayerRef;
use pocketmine\ThreadSafe;
use pocketmine\Thread;
use function socket_create;
use function socket_bind;
use function socket_set_nonblock;
use function socket_recvfrom;
use function socket_sendto;
use function socket_close;
use function socket_select;
use function inet_ntop;
use function strlen;
use function ord;
use function chr;
use function bin2hex;
use function hex2bin;

final class Protocol84NetworkAdapter implements NetworkPort {
    private const MTU = 1460;
    private const RAKLIB_MAGIC = "\x00\xff\xff\x00\xfe\xfe\xfe\xfe\xfd\xfd\xfd\xfd\x12\x34\x56\x78";

    private ?int $socket = null;
    private string $bindAddress = "0.0.0.0";
    private int $bindPort = 19132;
    private ThreadSafe $inboundQueue;
    private ThreadSafe $outboundQueue;
    private array $connectedPlayers = []; // address:port => PlayerRef
    private bool $running = false;
    private ?Thread $networkThread = null;
    private string $serverName = "Khronos Server";
    private int $serverId;
    private ThreadSafe $commandQueue;

    public function __construct(string $bindAddress = "0.0.0.0", int $bindPort = 19132) {
        $this->bindAddress = $bindAddress;
        $this->bindPort = $bindPort;
        $this->inboundQueue = new ThreadSafe();
        $this->outboundQueue = new ThreadSafe();
        $this->commandQueue = new ThreadSafe();
        $this->serverId = random_int(1, PHP_INT_MAX);
    }

    public function start(): void {
        if ($this->running) {
            return;
        }

        $this->socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($this->socket === false) {
            throw new \RuntimeException("Failed to create UDP socket: " . socket_strerror(socket_last_error()));
        }

        socket_set_nonblock($this->socket);
        
        if (!socket_bind($this->socket, $this->bindAddress, $this->bindPort)) {
            throw new \RuntimeException("Failed to bind socket: " . socket_strerror(socket_last_error()));
        }

        $this->running = true;
        $this->networkThread = new class($this) extends Thread {
            private Protocol84NetworkAdapter $adapter;
            
            public function __construct(Protocol84NetworkAdapter $adapter) {
                $this->adapter = $adapter;
            }
            
            public function run(): void {
                $this->registerClassLoader();
                $this->adapter->networkLoop();
            }
        };
        $this->networkThread->setClassLoader(\pocketmine\Server::getInstance()->getLoader());
        $this->networkThread->start(Thread::INHERIT_ALL);
    }

    public function shutdown(): void {
        $this->running = false;
        if ($this->networkThread !== null) {
            $this->networkThread->join();
            $this->networkThread = null;
        }
        if ($this->socket !== null) {
            socket_close($this->socket);
            $this->socket = null;
        }
    }

    private function networkLoop(): void {
        $read = [$this->socket];
        $write = [];
        $except = [];

        while ($this->running) {
            $readCopy = $read;
            $writeCopy = $write;
            $exceptCopy = $except;

            $numChanged = @socket_select($readCopy, $writeCopy, $exceptCopy, 0, 50000); // 50ms timeout
            
            if ($numChanged === false) {
                continue;
            }

            // Handle inbound packets
            if (in_array($this->socket, $readCopy)) {
                $this->handleIncoming();
            }

            // Handle outbound packets
            $this->handleOutgoing();

            // Process commands
            $this->processCommands();
        }
    }

    private function handleIncoming(): void {
        $buffer = "";
        $address = "";
        $port = 0;
        
        $bytes = @socket_recvfrom($this->socket, $buffer, self::MTU, 0, $address, $port);
        
        if ($bytes === false || $bytes === 0) {
            return;
        }

        $addrKey = $address . ":" . $port;
        
        // Handle RakLib protocol
        if ($bytes >= 1) {
            $packetId = ord($buffer[0]);
            
            switch ($packetId) {
                case Info::UNCONNECTED_PING:
                    $this->handleUnconnectedPing($buffer, $address, $port);
                    break;
                case Info::UNCONNECTED_PING_OPEN_CONNECTIONS:
                    $this->handleOpenConnections($buffer, $address, $port);
                    break;
                case Info::OPEN_CONNECTION_REQUEST_1:
                    $this->handleOpenConnectionRequest1($buffer, $address, $port);
                    break;
                case Info::OPEN_CONNECTION_REQUEST_2:
                    $this->handleOpenConnectionRequest2($buffer, $address, $port);
                    break;
                default:
                    // Connected session packets
                    if ($bytes > 1) {
                        $this->inboundQueue[] = [$addrKey, $buffer];
                    }
            }
        }
    }

    private function handleOutgoing(): void {
        while (true) {
            $item = $this->outboundQueue->shift();
            if ($item === null) {
                break;
            }
            
            [$playerRef, $packet] = $item;
            
            // Find address for this player
            $addrKey = $this->findAddressByPlayerRef($playerRef);
            if ($addrKey === null) {
                continue;
            }
            
            [$address, $port] = explode(":", $addrKey, 2);
            $port = (int)$port;
            
            $encoded = $this->encodePacket($packet);
            @socket_sendto($this->socket, $encoded, strlen($encoded), 0, $address, $port);
        }
    }

    private function findAddressByPlayerRef(PlayerRef $playerRef): ?string {
        foreach ($this->connectedPlayers as $addrKey => $ref) {
            if ($ref->entityId === $playerRef->entityId) {
                return $addrKey;
            }
        }
        return null;
    }

    private function processCommands(): void {
        while (true) {
            $cmd = $this->commandQueue->shift();
            if ($cmd === null) {
                break;
            }
            // Handle internal commands
        }
    }

    private function handleUnconnectedPing(string $buffer, string $address, int $port): void {
        // Respond with UNCONNECTED_PONG
        if (strlen($buffer) < 17) return; // Magic + timestamp + clientId
        
        $timestamp = substr($buffer, 17); // 16 bytes magic + clientId
        $response = chr(Info::UNCONNECTED_PONG) . self::RAKLIB_MAGIC . $timestamp . $this->getServerInfo();
        @socket_sendto($this->socket, $response, strlen($response), 0, $address, $port);
    }

    private function handleOpenConnections(string $buffer, string $address, int $port): void {
        // Similar to ping but for open connections
        $this->handleUnconnectedPing($buffer, $address, $port);
    }

    private function handleOpenConnectionRequest1(string $buffer, string $address, int $port): void {
        if (strlen($buffer) < 19) return; // 1 + 16 magic + 1 protocol + 1 MTU
        
        $protocol = ord($buffer[17]);
        $mtuSize = (ord($buffer[18]) << 8) | ord($buffer[19]);
        
        if ($protocol !== Info::CURRENT_PROTOCOL) {
            // Send incompatible protocol version
            return;
        }
        
        $reply = chr(Info::OPEN_CONNECTION_REPLY_1) . self::RAKLIB_MAGIC;
        $reply .= pack("J", 0); // serverId
        $reply .= pack("C", 0); // useSecurity
        $reply .= pack("n", $mtuSize); // mtuSize
        
        @socket_sendto($this->socket, $reply, strlen($reply), 0, $address, $port);
    }

    private function handleOpenConnectionRequest2(string $buffer, string $address, int $port): void {
        // Client has sent its cookie, we reply with our cookie
        $reply = chr(Info::OPEN_CONNECTION_REPLY_2) . self::RAKLIB_MAGIC;
        $reply .= pack("J", $this->serverId);
        $reply .= pack("C", 0); // useSecurity
        
        @socket_sendto($this->socket, $reply, strlen($reply), 0, $address, $port);
    }

    private function getServerInfo(): string {
        // Format: serverId + serverName + protocol + version + playerCount + maxPlayers + gameId
        $info = pack("J", $this->serverId);
        $info .= chr(strlen($this->serverName)) . $this->serverName;
        $info .= pack("v", Info::CURRENT_PROTOCOL);
        $info .= "1.0.0"; // version
        $info .= pack("v", count($this->connectedPlayers));
        $info .= pack("v", 100); // max players
        $info .= pack("J", 123456789); // gameId
        return $info;
    }

    public function sendPacket(PlayerRef $player, DataPacket $packet): void {
        $this->outboundQueue[] = [$player, $packet];
    }

    public function broadcastPacket(iterable $players, DataPacket $packet): void {
        foreach ($players as $player) {
            $this->outboundQueue[] = [$player, $packet];
        }
    }

    public function disconnect(PlayerRef $player, string $reason): void {
        $pk = new DisconnectPacket();
        $pk->message = $reason;
        $pk->hideDisconnectionScreen = false;
        $this->sendPacket($player, $pk);
        
        // Remove from connected players
        $this->removePlayer($player);
    }

    private function removePlayer(PlayerRef $player): void {
        foreach ($this->connectedPlayers as $addrKey => $ref) {
            if ($ref->entityId === $player->entityId) {
                unset($this->connectedPlayers[$addrKey]);
                break;
            }
        }
    }

    public function processPendingCommands(): void {
        // Process inbound packets into domain events
        while (true) {
            $item = $this->inboundQueue->shift();
            if ($item === null) {
                break;
            }
            
            [$addrKey, $buffer] = $item;
            $this->processInboundPacket($addrKey, $buffer);
        }
    }

    private function processInboundPacket(string $addrKey, string $buffer): void {
        if (strlen($buffer) < 1) return;
        
        $packetId = ord($buffer[0]);
        
        // Skip RakLib internal packets
        if ($packetId < Info::DATA_PACKET_0 || $packetId > Info::DATA_PACKET_F) {
            return;
        }
        
        // Create PlayerRef if new
        if (!isset($this->connectedPlayers[$addrKey])) {
            $playerRef = new PlayerRef(
                $addrKey, // uniqueId from address
                $this->generateEntityId(),
                "Player_" . substr($addrKey, 0, 8)
            );
            $this->connectedPlayers[$addrKey] = $playerRef;
        }
        
        $playerRef = $this->connectedPlayers[$addrKey];
        
        // Decode packet
        $packet = $this->decodePacket($buffer);
        if ($packet !== null) {
            $this->commandQueue[] = ["packet", $playerRef, $packet];
        }
    }

    private function decodePacket(string $buffer): ?DataPacket {
        $packetId = ord($buffer[0]);
        $className = "pocketmine\\network\\protocol\\" . Info::PACKET_NAMES[$packetId] ?? null;
        
        if ($className === null || !class_exists($className)) {
            return null;
        }
        
        $packet = new $className();
        $packet->setBuffer($buffer);
        $packet->decode();
        return $packet;
    }

    private function encodePacket(DataPacket $packet): string {
        $packet->encode();
        return $packet->getBuffer();
    }

    private function generateEntityId(): int {
        return random_int(1, PHP_INT_MAX);
    }

    public function flushOutboundPackets(): void {
        // Handled in networkLoop
    }

    public function setServerName(string $name): void {
        $this->serverName = $name;
    }

    public function getConnectedPlayers(): array {
        return array_values($this->connectedPlayers);
    }
}
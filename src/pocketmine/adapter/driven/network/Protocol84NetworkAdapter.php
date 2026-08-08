<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\network;

use pocketmine\core\thread\NetworkThread;
use pocketmine\protocol\DataPacket;
use pocketmine\protocol\DisconnectPacket;
use pocketmine\protocol\Info;
use pocketmine\port\driven\NetworkPort;
use pocketmine\port\driven\PlayerRef;
use pmmp\thread\Thread;
use pmmp\thread\ThreadSafeArray;
use function socket_create;
use function socket_bind;
use function socket_set_nonblock;
use function socket_recvfrom;
use function socket_sendto;
use function socket_close;
use function socket_select;
use function strlen;
use function ord;
use function chr;
use function pack;
use function base64_encode;
use function base64_decode;
use function json_encode;
use function json_decode;
use function explode;

/**
 * Protocol 84 network adapter.
 *
 * Owns the UDP socket on the main thread; all socket I/O (recv/send) and all
 * protocol encode/decode happens here. Outbound frames are handed to a
 * NetworkThread worker for batch compression through thread-safe queues
 * (scalar payloads only), then flushed to the socket by the main thread.
 */
final class Protocol84NetworkAdapter implements NetworkPort {
    private const MTU = 1460;
    private const RAKLIB_MAGIC = "\x00\xff\xff\x00\xfe\xfe\xfe\xfe\xfd\xfd\xfd\xfd\x12\x34\x56\x78";

    private ?object $socket = null;
    private string $bindAddress = "0.0.0.0";
    private int $bindPort = 19132;
    /** @var ThreadSafeArray<int, string> compressed frames ready to send: [addrKey, payload] */
    private ThreadSafeArray $outboundQueue;
    /** @var ThreadSafeArray<int, string> decoded inbound events: json_encode([addrKey, packetId, payload]) */
    private ThreadSafeArray $inboundQueue;
    /** @var array<string, PlayerRef> address:port => PlayerRef */
    private array $connectedPlayers = [];
    private bool $running = false;
    private ?NetworkThread $networkThread = null;
    /** Whether this adapter created the thread itself (and owns its lifecycle). */
    private bool $ownsNetworkThread = false;
    private string $serverName = "Khronos Server";
    private int $serverId;

    public function __construct(string $bindAddress = "0.0.0.0", int $bindPort = 19132) {
        $this->bindAddress = $bindAddress;
        $this->bindPort = $bindPort;
        $this->outboundQueue = new ThreadSafeArray();
        $this->inboundQueue = new ThreadSafeArray();
        $this->serverId = random_int(1, PHP_INT_MAX);
    }

    /**
     * Override the bind port before start(). Only honored while the socket is
     * not yet bound; used by tests to avoid clashing with the default port.
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

        // Start the packet pipeline worker (compression/batching). Socket I/O
        // stays here. When the kernel injects its own NetworkThread (the
        // single pipeline worker owned and started by Kernel::run()), reuse it
        // instead of spawning a second thread.
        if ($this->networkThread === null) {
            $this->networkThread = new NetworkThread();
            $this->ownsNetworkThread = true;
            $this->networkThread->start(Thread::INHERIT_ALL);
        }

        $this->running = true;
    }

    public function shutdown(): void {
        $this->running = false;
        // Only reap a thread this adapter created; an injected kernel thread
        // is shut down and joined by Kernel::shutdown().
        if ($this->ownsNetworkThread && $this->networkThread !== null) {
            $this->networkThread->shutdown();
            $this->networkThread->join();
            $this->networkThread = null;
            $this->ownsNetworkThread = false;
        }
        if ($this->socket !== null) {
            socket_close($this->socket);
            $this->socket = null;
        }
    }

    /**
     * Point this adapter at the kernel's NetworkThread (the single packet
     * pipeline worker). The kernel owns its lifecycle: start it via
     * Kernel::run() and shut it down via Kernel::shutdown().
     */
    public function setNetworkThread(NetworkThread $thread): void {
        $this->networkThread = $thread;
        $this->ownsNetworkThread = false;
    }

    /**
     * Called from the kernel main loop: receive inbound datagrams and decode
     * them into scalar events, then flush compressed outbound frames.
     */
    public function processPendingCommands(): void {
        $this->handleIncoming();
        $this->drainCompressedOutbound();
    }

    public function flushOutboundPackets(): void {
        $this->drainCompressedOutbound();
    }

    private function handleIncoming(): void {
        if ($this->socket === null) {
            return;
        }
        while (true) {
            $buffer = "";
            $address = "";
            $port = 0;
            $bytes = @socket_recvfrom($this->socket, $buffer, self::MTU, 0, $address, $port);
            if ($bytes === false || $bytes === 0) {
                break;
            }

            $addrKey = $address . ":" . $port;
            $packetId = ord($buffer[0]);

            // RakLib handshake packets are handled directly (stateless replies).
            if ($packetId === Info::UNCONNECTED_PING) {
                $this->handleUnconnectedPing($buffer, $address, $port);
                continue;
            }
            if ($packetId === Info::UNCONNECTED_PING_OPEN_CONNECTIONS) {
                $this->handleUnconnectedPing($buffer, $address, $port);
                continue;
            }
            if ($packetId === Info::OPEN_CONNECTION_REQUEST_1) {
                $this->handleOpenConnectionRequest1($buffer, $address, $port);
                continue;
            }
            if ($packetId === Info::OPEN_CONNECTION_REQUEST_2) {
                $this->handleOpenConnectionRequest2($buffer, $address, $port);
                continue;
            }

            // Game packets: decode on the main thread, emit scalar event.
            $this->inboundQueue[] = json_encode([$addrKey, $packetId, base64_encode($buffer)]);
        }
    }

    private function drainCompressedOutbound(): void {
        if ($this->networkThread === null || $this->socket === null) {
            return;
        }
        while (($frame = $this->networkThread->getSendQueue()->shift()) !== null) {
            $decoded = json_decode($frame, true);
            if (!is_array($decoded) || count($decoded) < 2) {
                continue;
            }
            [$addrKey, $payload] = $decoded;
            if (!is_string($addrKey) || !is_string($payload)) {
                continue;
            }
            $payload = base64_decode($payload, true);
            if ($payload === false) {
                continue;
            }
            $parts = explode(":", $addrKey, 2);
            if (count($parts) !== 2) {
                continue;
            }
            [$address, $port] = $parts;
            @socket_sendto($this->socket, $payload, strlen($payload), 0, $address, (int)$port);
        }
    }

    private function handleUnconnectedPing(string $buffer, string $address, int $port): void {
        if ($this->socket === null || strlen($buffer) < 17) {
            return;
        }
        $timestamp = substr($buffer, 1, 8);
        $response = chr(Info::UNCONNECTED_PONG) . $timestamp . self::RAKLIB_MAGIC . $this->getServerInfo();
        @socket_sendto($this->socket, $response, strlen($response), 0, $address, $port);
    }

    private function handleOpenConnectionRequest1(string $buffer, string $address, int $port): void {
        if ($this->socket === null || strlen($buffer) < 19) {
            return;
        }
        $protocol = ord($buffer[17]);
        $mtuSize = (ord($buffer[18]) << 8) | ord($buffer[19]);
        if ($protocol !== Info::CURRENT_PROTOCOL) {
            return;
        }
        $reply = chr(Info::OPEN_CONNECTION_REPLY_1) . self::RAKLIB_MAGIC;
        $reply .= pack("J", 0);
        $reply .= pack("C", 0);
        $reply .= pack("n", $mtuSize);
        @socket_sendto($this->socket, $reply, strlen($reply), 0, $address, $port);
    }

    private function handleOpenConnectionRequest2(string $buffer, string $address, int $port): void {
        if ($this->socket === null) {
            return;
        }
        $reply = chr(Info::OPEN_CONNECTION_REPLY_2) . self::RAKLIB_MAGIC;
        $reply .= pack("J", $this->serverId);
        $reply .= pack("C", 0);
        @socket_sendto($this->socket, $reply, strlen($reply), 0, $address, $port);
    }

    private function getServerInfo(): string {
        $info = pack("J", $this->serverId);
        $info .= chr(strlen($this->serverName)) . $this->serverName;
        $info .= pack("v", Info::CURRENT_PROTOCOL);
        $info .= "1.0.0";
        $info .= pack("v", count($this->connectedPlayers));
        $info .= pack("v", 100);
        $info .= pack("J", 123456789);
        return $info;
    }

    public function sendPacket(PlayerRef $player, DataPacket $packet): void {
        $addrKey = $this->findAddressByPlayerRef($player);
        if ($addrKey === null || $this->networkThread === null) {
            return;
        }
        $packet->encode();
        $this->networkThread->queueOutboundFrame($addrKey, $packet->getBuffer());
    }

    /**
     * Send a packet to a raw address (no PlayerRef registration required).
     * Used for pre-session replies such as login-failed status.
     */
    public function sendRawPacket(string $addrKey, DataPacket $packet): void {
        if ($this->networkThread === null) {
            return;
        }
        $packet->encode();
        $this->networkThread->queueOutboundFrame($addrKey, $packet->getBuffer());
    }

    /** Bind a logged-in player's address to its PlayerRef for sends. */
    public function registerPlayer(string $addrKey, PlayerRef $playerRef): void {
        $this->connectedPlayers[$addrKey] = $playerRef;
    }

    /** Forget a player's address mapping (on disconnect/shutdown). */
    public function unregisterPlayer(PlayerRef $playerRef): void {
        $this->removePlayer($playerRef);
    }

    public function broadcastPacket(iterable $players, DataPacket $packet): void {
        foreach ($players as $player) {
            $this->sendPacket($player, $packet);
        }
    }

    public function disconnect(PlayerRef $player, string $reason): void {
        $pk = new DisconnectPacket();
        $pk->message = $reason;
        $pk->hideDisconnectionScreen = false;
        $this->sendPacket($player, $pk);
        $this->removePlayer($player);
    }

    /**
     * Pop decoded inbound events for the kernel to dispatch as domain commands.
     * @return array<int, array{0: string, 1: int, 2: string}>
     */
    public function pollInboundEvents(): array {
        $events = [];
        while (($item = $this->inboundQueue->shift()) !== null) {
            $decoded = json_decode($item, true);
            if (!is_array($decoded) || count($decoded) < 3) {
                continue;
            }
            $events[] = [$decoded[0], (int)$decoded[1], base64_decode((string)$decoded[2])];
        }
        return $events;
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

    public function setServerName(string $name): void {
        $this->serverName = $name;
    }

    public function getConnectedPlayers(): array {
        return array_values($this->connectedPlayers);
    }
}

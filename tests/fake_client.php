<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/autoload.php";
require_once __DIR__ . "/helpers.php";

use pocketmine\protocol\BatchPacket;
use pocketmine\protocol\Info;
use pocketmine\protocol\LoginPacket;
use raklib\protocol\ACK;
use raklib\protocol\CLIENT_CONNECT_DataPacket;
use raklib\protocol\CLIENT_HANDSHAKE_DataPacket;
use raklib\protocol\DATA_PACKET_4;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\OPEN_CONNECTION_REQUEST_1;
use raklib\protocol\OPEN_CONNECTION_REQUEST_2;
use raklib\protocol\PacketReliability;
use raklib\protocol\SERVER_HANDSHAKE_DataPacket;
use raklib\protocol\UNCONNECTED_PING;
use raklib\protocol\UNCONNECTED_PONG;
use raklib\RakLib;
use pocketmine\utils\BinaryStream;

if (class_exists(FakeClient::class, false)) {
    return; // already defined by an older test file that inlines it
}

/**
 * Minimal protocol-84 client over a real UDP socket speaking REAL RakNet.
 *
 * Drives the offline handshake, the connected handshake (CONNECTION_REQUEST
 * -> SERVER_HANDSHAKE -> NEW_INCOMING_CONNECTION) and then wraps game
 * packets in BatchPackets inside reliable-ordered encapsulated frames - the
 * same wire behaviour as a real MCPE 0.15.x client. Incoming DATA_PACKETs
 * are acknowledged, split packets reassembled, and reliable packets
 * reordered by message index.
 */
final class FakeClient {
    private const MTU = 1492;

    /** @var resource */
    private $socket;
    private int $sendSeq = 0;
    private int $messageIndex = 0;
    private int $orderIndex = 0;
    private int $splitId = 0;
    /** @var list<int> server DATA_PACKET seqs awaiting an ACK */
    private array $ackSeqs = [];
    /** @var array<int, EncapsulatedPacket> reliable packets waiting for their predecessors */
    private array $pending = [];
    private int $lastMessageIndex = -1;
    /** @var array<int, array<int, EncapsulatedPacket>> splitID => splitIndex => fragment */
    private array $splits = [];
    /** @var list<string> delivered game-packet buffers, in order */
    private array $gameBuffer = [];
    private bool $collectGame = false;
    private bool $gotHandshake = false;

    public function __construct(private readonly int $port) {
        $this->socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        socket_set_nonblock($this->socket);
        socket_connect($this->socket, '127.0.0.1', $port);
    }

    public function close(): void {
        socket_close($this->socket);
    }

    public function sendDatagram(string $data): void {
        socket_sendto($this->socket, $data, strlen($data), 0, '127.0.0.1', $this->port);
    }

    /** @return list<string> all datagrams currently in the receive buffer */
    private function readDatagrams(): array {
        $out = [];
        while (true) {
            $buffer = '';
            $from = '';
            $port = 0;
            $n = @socket_recvfrom($this->socket, $buffer, 65536, 0, $from, $port);
            if ($n === false || $n === 0) {
                break;
            }
            $out[] = $buffer;
        }
        return $out;
    }

    // --- RakNet offline handshake ------------------------------------------

    public function handshake(?callable $tick = null, float $timeoutSec = 4.0): void {
        $ping = new UNCONNECTED_PING();
        $ping->pingID = time();
        $ping->encode();
        $this->sendDatagram($ping->buffer);
        if (!$this->awaitRaw(fn(string $d): bool => ord($d[0]) === UNCONNECTED_PONG::$ID, $tick, $timeoutSec)) {
            throw new RuntimeException('no UNCONNECTED_PONG from server');
        }

        $req1 = new OPEN_CONNECTION_REQUEST_1();
        $req1->protocol = RakLib::PROTOCOL;
        $req1->mtuSize = self::MTU;
        $req1->encode();
        $this->sendDatagram($req1->buffer);
        if (!$this->awaitRaw(fn(string $d): bool => ord($d[0]) === 0x06, $tick, $timeoutSec)) {
            throw new RuntimeException('no OPEN_CONNECTION_REPLY_1 from server');
        }

        $req2 = new OPEN_CONNECTION_REQUEST_2();
        $req2->clientID = random_int(1, PHP_INT_MAX);
        $req2->serverAddress = '127.0.0.1';
        $req2->serverPort = $this->port;
        $req2->mtuSize = self::MTU;
        $req2->encode();
        $this->sendDatagram($req2->buffer);
        if (!$this->awaitRaw(fn(string $d): bool => ord($d[0]) === 0x08, $tick, $timeoutSec)) {
            throw new RuntimeException('no OPEN_CONNECTION_REPLY_2 from server');
        }
    }

    // --- RakNet connected handshake ----------------------------------------

    /**
     * CONNECTION_REQUEST -> (SERVER_HANDSHAKE) -> NEW_INCOMING_CONNECTION.
     * After this returns the transport is fully connected and game packets
     * can flow in both directions.
     */
    public function connect(?callable $tick = null, float $timeoutSec = 4.0): void {
        $connect = new CLIENT_CONNECT_DataPacket();
        $connect->clientID = random_int(1, PHP_INT_MAX);
        $connect->sendPing = 0;
        $connect->encode();
        $this->sendEncapsulated($connect->buffer, PacketReliability::RELIABLE_ORDERED);

        if (!$this->awaitPumped(fn(): bool => $this->gotHandshake, $tick, $timeoutSec)) {
            throw new RuntimeException('no SERVER_HANDSHAKE from server');
        }
        $this->gotHandshake = false;

        $handshake = new CLIENT_HANDSHAKE_DataPacket();
        $handshake->address = '127.0.0.1';
        $handshake->port = $this->port;
        $handshake->sendPing = 0;
        $handshake->sendPong = 100;
        $handshake->encode();
        $this->sendEncapsulated($handshake->buffer, PacketReliability::RELIABLE_ORDERED);

        // From here on, delivered payloads are game packets.
        $this->collectGame = true;
    }

    // --- RakNet transport --------------------------------------------------

    /**
     * Send a raw game-packet body (id byte + fields) the way a real protocol-84
     * client does: wrapped in a compressed BatchPacket, then prefixed with the
     * 0xfe wire marker the server's RakLibInterface expects on every frame.
     */
    public function sendRawBuffer(string $buffer): void {
        $inner = pack('N', strlen($buffer)) . $buffer;
        $batch = new BatchPacket();
        $batch->payload = zlib_encode($inner, ZLIB_ENCODING_DEFLATE, 7);
        $batch->encode();
        $this->sendEncapsulated(chr(0xfe) . $batch->getBuffer(), PacketReliability::RELIABLE_ORDERED);
    }

    /** Send a game packet (encode + batch-wrap + reliable frame). */
    public function sendGamePacket(\pocketmine\protocol\DataPacket $packet): void {
        $packet->encode();
        $this->sendRawBuffer($packet->getBuffer());
    }

    /**
     * Send a raw RakNet control payload (e.g. CLIENT_DISCONNECT 0x15) in a
     * reliable frame WITHOUT the 0xfe game prefix - the server's Session
     * routes control ids before the game-data path.
     */
    public function sendControl(string $buffer): void {
        $this->sendEncapsulated($buffer, PacketReliability::RELIABLE_ORDERED);
    }

    public function sendLogin(string $username, string $uuid): void {
        $stream = new BinaryStream();
        $stream->putInt(84);

        $chain = json_encode(['chain' => [self::jwt([
            'extraData' => ['displayName' => $username, 'identity' => $uuid],
        ])]]);
        $skin = self::jwt([
            'ClientRandomId' => 12345,
            'ServerAddress' => '127.0.0.1:' . $this->port,
            'SkinData' => base64_encode(''),
            'SkinId' => 'Standard_Custom',
        ]);

        $inner = new BinaryStream();
        $inner->putLInt(strlen((string)$chain));
        $inner->put((string)$chain);
        $inner->putLInt(strlen((string)$skin));
        $inner->put((string)$skin);

        $compressed = zlib_encode($inner->getBuffer(), ZLIB_ENCODING_DEFLATE, 7);
        $stream->putInt(strlen($compressed));
        $stream->put($compressed);

        $login = new LoginPacket();
        $login->setBuffer(chr(Info::LOGIN_PACKET) . $stream->getBuffer());
        $this->sendGamePacket($login);
    }

    private static function jwt(array $payload): string {
        $b64 = static fn(string $s): string => base64_encode($s);
        return $b64('{"alg":"ES384"}') . '.' . $b64(json_encode($payload)) . '.' . $b64(str_repeat("\x00", 97));
    }

    /**
     * Enqueue one encapsulated payload (splitting oversized buffers like a
     * real client) and flush it in a DATA_PACKET datagram immediately.
     */
    private function sendEncapsulated(string $buffer, int $reliability = PacketReliability::RELIABLE_ORDERED): void {
        $pk = new EncapsulatedPacket();
        $pk->reliability = $reliability;
        $pk->buffer = $buffer;
        if ($reliability >= PacketReliability::RELIABLE && $reliability !== PacketReliability::UNRELIABLE_WITH_ACK_RECEIPT) {
            $pk->messageIndex = $this->messageIndex++;
        }
        if ($reliability === PacketReliability::RELIABLE_ORDERED || $reliability === PacketReliability::RELIABLE_SEQUENCED) {
            $pk->orderIndex = $this->orderIndex++;
            $pk->orderChannel = 0;
        }

        $max = 1200; // leave room for the datagram + frame headers
        if (strlen($buffer) > $max) {
            $splitId = $this->splitId++ % 65536;
            $parts = str_split($buffer, $max);
            foreach ($parts as $i => $part) {
                $sp = new EncapsulatedPacket();
                $sp->hasSplit = true;
                $sp->splitCount = count($parts);
                $sp->splitID = $splitId;
                $sp->splitIndex = $i;
                $sp->reliability = $reliability;
                $sp->buffer = $part;
                $sp->messageIndex = $i > 0 ? $this->messageIndex++ : $pk->messageIndex;
                if ($reliability === PacketReliability::RELIABLE_ORDERED) {
                    $sp->orderIndex = $pk->orderIndex;
                    $sp->orderChannel = 0;
                }
                $this->sendDataPacket($sp);
            }
            return;
        }
        $this->sendDataPacket($pk);
    }

    private function sendDataPacket(EncapsulatedPacket $pk): void {
        $dp = new DATA_PACKET_4();
        $dp->seqNumber = $this->sendSeq++;
        $dp->packets[] = $pk->toBinary();
        $dp->encode();
        $this->sendDatagram($dp->buffer);
    }

    private function flushAcks(): void {
        if (empty($this->ackSeqs)) {
            return;
        }
        $ack = new ACK();
        $ack->packets = $this->ackSeqs;
        $ack->encode();
        $this->sendDatagram($ack->buffer);
        $this->ackSeqs = [];
    }

    /** Read + parse all pending datagrams (DATA_PACKETs only; ACK/NACK ignored). */
    private function pump(): void {
        foreach ($this->readDatagrams() as $datagram) {
            if ($datagram === '') {
                continue;
            }
            $id = ord($datagram[0]);
            if ($id >= 0x80 && $id <= 0x8f) {
                $this->handleDataPacket($datagram);
            }
        }
    }

    private function handleDataPacket(string $datagram): void {
        $dp = new DATA_PACKET_4();
        $dp->buffer = $datagram;
        $dp->decode();
        if ($dp->seqNumber !== null) {
            $this->ackSeqs[] = $dp->seqNumber;
        }
        foreach ($dp->packets as $pk) {
            if ($pk instanceof EncapsulatedPacket) {
                $this->handleEncapsulated($pk);
            }
        }
    }

    /**
     * Ordered delivery runs on FRAGMENT message indexes, matching the
     * server's reliable window: every reliable packet - split fragment or
     * not - must arrive in message-index order. A split packet is delivered
     * when its last fragment arrives (fragments of one packet carry
     * consecutive indexes, so index order == fragment order).
     */
    private function handleEncapsulated(EncapsulatedPacket $pk): void {
        if ($pk->messageIndex === null) {
            $this->consume($pk->buffer); // unreliable: deliver immediately
            return;
        }
        $this->pending[$pk->messageIndex] = $pk;
        while (isset($this->pending[$this->lastMessageIndex + 1])) {
            $this->lastMessageIndex++;
            $pkt = $this->pending[$this->lastMessageIndex];
            unset($this->pending[$this->lastMessageIndex]);
            if ($pkt->hasSplit) {
                $this->splits[$pkt->splitID][$pkt->splitIndex] = $pkt;
                if (isset($pkt->splitCount) && count($this->splits[$pkt->splitID]) === $pkt->splitCount) {
                    $joined = '';
                    for ($i = 0; $i < $pkt->splitCount; $i++) {
                        $joined .= $this->splits[$pkt->splitID][$i]->buffer;
                    }
                    unset($this->splits[$pkt->splitID]);
                    $this->consume($joined);
                }
            } else {
                $this->consume($pkt->buffer);
            }
        }
    }

    private function consume(string $buffer): void {
        if ($buffer === '' || strlen($buffer) < 1) {
            return;
        }
        if (ord($buffer[0]) === SERVER_HANDSHAKE_DataPacket::$ID) {
            $this->gotHandshake = true;
        }
        if ($this->collectGame) {
            // Protocol-84 wire: the server 0xfe-prefixes every game frame;
            // strip it so readGamePackets sees the raw packet (id + body).
            $this->gameBuffer[] = (ord($buffer[0]) === 0xfe && strlen($buffer) > 1) ? substr($buffer, 1) : $buffer;
        }
    }

    /**
     * Decode all delivered game packets: pump the transport, then unpack each
     * BatchPacket into its inner length-prefixed packet bodies.
     * @return list<array{0: int, 1: string}>
     */
    public function readGamePackets(): array {
        $this->pump();
        $this->flushAcks();
        $packets = [];
        while (($buffer = array_shift($this->gameBuffer)) !== null) {
            $id = ord($buffer[0]);
            if ($id === Info::BATCH_PACKET) {
                $batch = new BatchPacket();
                $batch->setBuffer($buffer, 1);
                $batch->decode();
                $payload = zlib_decode($batch->payload);
                if ($payload === false) {
                    continue;
                }
                $poff = 0;
                $plen = strlen($payload);
                while ($poff + 4 <= $plen) {
                    $pkLen = unpack('N', substr($payload, $poff, 4))[1];
                    $poff += 4;
                    if ($pkLen <= 0 || $poff + $pkLen > $plen) {
                        break;
                    }
                    $pkBuf = substr($payload, $poff, $pkLen);
                    $poff += $pkLen;
                    $packets[] = [ord($pkBuf[0]), $pkBuf];
                }
            } else {
                $packets[] = [$id, $buffer];
            }
        }
        return $packets;
    }

    // --- Wait helpers ------------------------------------------------------

    private function awaitRaw(callable $pred, ?callable $tick, float $timeoutSec): bool {
        $deadline = microtime(true) + $timeoutSec;
        while (microtime(true) < $deadline) {
            if ($tick !== null) {
                $tick();
            }
            foreach ($this->readDatagrams() as $datagram) {
                if ($pred($datagram)) {
                    return true;
                }
            }
            usleep(10000);
        }
        return false;
    }

    private function awaitPumped(callable $pred, ?callable $tick, float $timeoutSec): bool {
        $deadline = microtime(true) + $timeoutSec;
        while (microtime(true) < $deadline) {
            if ($tick !== null) {
                $tick();
            }
            $this->pump();
            $this->flushAcks();
            if ($pred()) {
                return true;
            }
            usleep(10000);
        }
        return false;
    }
}


<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\adapter\driven\network\Protocol84NetworkAdapter;
use pocketmine\protocol\BatchPacket;
use pocketmine\protocol\ContainerSetContentPacket;
use pocketmine\protocol\ContainerSetSlotPacket;
use pocketmine\protocol\FullChunkDataPacket;
use pocketmine\protocol\Info;
use pocketmine\protocol\LoginPacket;
use pocketmine\protocol\MobEquipmentPacket;
use pocketmine\protocol\MovePlayerPacket;
use pocketmine\protocol\PlayStatusPacket;
use pocketmine\protocol\PlayerActionPacket;
use pocketmine\protocol\RequestChunkRadiusPacket;
use pocketmine\protocol\TextPacket;
use pocketmine\protocol\UpdateBlockPacket;
use pocketmine\protocol\UseItemPacket;
use pocketmine\utils\BinaryStream;
use raklib\protocol\ACK;
use raklib\protocol\CLIENT_CONNECT_DataPacket;
use raklib\protocol\CLIENT_DISCONNECT_DataPacket;
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

// --- Manual parsers --------------------------------------------------------
// Protocol-84 client-bound packets have no-op decode() (they are encode-only),
// so the test parses their raw bodies directly.

function psStatus(string $buf): int {
    $s = new BinaryStream($buf, 1);
    return $s->getInt();
}

function sgFields(string $buf): array {
    $s = new BinaryStream($buf, 1);
    return [
        'seed' => $s->getInt(),
        'dimension' => $s->getByte(),
        'generator' => $s->getInt(),
        'gamemode' => $s->getInt(),
        'eid' => $s->getLong(),
        'spawnX' => $s->getInt(),
        'spawnY' => $s->getInt(),
        'spawnZ' => $s->getInt(),
        'x' => $s->getFloat(),
        'y' => $s->getFloat(),
        'z' => $s->getFloat(),
    ];
}

function sspFields(string $buf): array {
    $s = new BinaryStream($buf, 1);
    return ['x' => $s->getInt(), 'y' => $s->getInt(), 'z' => $s->getInt()];
}

function shFields(string $buf): int {
    $s = new BinaryStream($buf, 1);
    return $s->getInt();
}

function sdFields(string $buf): int {
    $s = new BinaryStream($buf, 1);
    return $s->getInt();
}

function stTime(string $buf): int {
    $s = new BinaryStream($buf, 1);
    return $s->getInt();
}

function advFields(string $buf): array {
    $s = new BinaryStream($buf, 1);
    return ['flags' => $s->getInt(), 'user' => $s->getInt(), 'global' => $s->getInt()];
}

function plEntries(string $buf): array {
    $s = new BinaryStream($buf, 1);
    $type = $s->getByte();
    $count = $s->getInt();
    $out = [];
    for ($i = 0; $i < $count; $i++) {
        $uuid = $s->getUUID();
        if ($type === 0) {
            $eid = $s->getLong();
            $name = $s->getString();
            $s->getString(); // slim flag
            $s->getString(); // skin
            $out[] = ['uuid' => $uuid->toString(), 'eid' => $eid, 'name' => $name];
        } else {
            $out[] = ['uuid' => $uuid->toString()];
        }
    }
    return $out;
}

function crFields(string $buf): int {
    $s = new BinaryStream($buf, 1);
    return $s->getInt();
}

function fcFields(string $buf): array {
    $s = new BinaryStream($buf, 1);
    $x = $s->getInt();
    $z = $s->getInt();
    $order = $s->getByte();
    $len = $s->getInt();
    return ['x' => $x, 'z' => $z, 'order' => $order, 'data' => substr($s->getBuffer(), $s->getOffset())];
}

function textPacket(string $buf): array {
    $s = new BinaryStream($buf, 1);
    $type = $s->getByte();
    if ($type === 2) { // translation
        return ['type' => $type, 'message' => $s->getString()];
    }
    if ($type === 1) { // chat: source + message
        $source = $s->getString();
        return ['type' => $type, 'source' => $source, 'message' => $s->getString()];
    }
    return ['type' => $type, 'message' => $s->getString()];
}

function blockIdAt(string $payload, int $x, int $z, int $y): int {
    $section = intdiv($y, 16);
    $localY = $y & 15;
    $index = $section * 4096 + $localY * 256 + $z * 16 + $x;
    return ord($payload[$index]);
}

/** Nibble at a given block in one of the three 8-section nibble planes. */
function nibbleAt(string $payload, int $planeBase, int $x, int $z, int $y): int {
    $section = intdiv($y, 16);
    $localY = $y & 15;
    $nibbleIndex = $localY * 256 + $z * 16 + $x;
    $byteIndex = $planeBase + $section * 2048 + intdiv($nibbleIndex, 2);
    $byte = ord($payload[$byteIndex]);
    return ($nibbleIndex & 1) === 0 ? ($byte & 0x0F) : ($byte >> 4);
}

function heightAt(string $payload, int $x, int $z): int {
    $base = 8 * (4096 + 2048 * 3);
    return ord($payload[$base + $z * 16 + $x]);
}

function ubFields(string $buf): array {
    $s = new BinaryStream($buf, 1);
    return [
        'x' => $s->getInt(),
        'z' => $s->getInt(),
        'y' => $s->getByte(),
        'blockId' => $s->getByte(),
        'flagsData' => $s->getByte(),
    ];
}

function cscFields(string $buf): array {
    $s = new BinaryStream($buf, 1);
    $out = ['windowid' => $s->getByte()];
    $count = $s->getShort();
    $out['slots'] = [];
    for ($i = 0; $i < $count; $i++) {
        $out['slots'][] = $s->getSlot();
    }
    return $out;
}

function cssFields(string $buf): array {
    $s = new BinaryStream($buf, 1);
    return [
        'windowid' => $s->getByte(),
        'slot' => $s->getShort(),
        'hotbarSlot' => $s->getShort(),
        'item' => $s->getSlot(),
    ];
}

function apFields(string $buf): array {
    $s = new BinaryStream($buf, 1);
    return [
        'uuid' => $s->getUUID()->toString(),
        'username' => $s->getString(),
        'eid' => $s->getLong(),
        'x' => $s->getFloat(),
        'y' => $s->getFloat(),
        'z' => $s->getFloat(),
    ];
}

function aeFields(string $buf): array {
    $s = new BinaryStream($buf, 1);
    return [
        'eid' => $s->getLong(),
        'type' => $s->getInt(),
        'x' => $s->getFloat(),
        'y' => $s->getFloat(),
        'z' => $s->getFloat(),
    ];
}

function aieFields(string $buf): array {
    $s = new BinaryStream($buf, 1);
    return [
        'eid' => $s->getLong(),
        'item' => $s->getSlot(),
        'x' => $s->getFloat(),
        'y' => $s->getFloat(),
        'z' => $s->getFloat(),
    ];
}

function meFields(string $buf): array {
    $s = new BinaryStream($buf, 1);
    return [
        'eid' => $s->getLong(),
        'x' => $s->getFloat(),
        'y' => $s->getFloat(),
        'z' => $s->getFloat(),
        'pitch' => $s->getByte() * (360.0 / 256),
        'yaw' => $s->getByte() * (360.0 / 256),
        'headYaw' => $s->getByte() * (360.0 / 256),
    ];
}

function mpFields(string $buf): array {
    $s = new BinaryStream($buf, 1);
    return [
        'eid' => $s->getLong(),
        'x' => $s->getFloat(),
        'y' => $s->getFloat(),
        'z' => $s->getFloat(),
        'yaw' => $s->getFloat(),
        'bodyYaw' => $s->getFloat(),
        'pitch' => $s->getFloat(),
        'mode' => $s->getByte(),
        'onGround' => $s->getByte(),
    ];
}

function reFields(string $buf): int {
    $s = new BinaryStream($buf, 1);
    return $s->getLong();
}

/** EntityEventPacket: eid (long) + event byte. */
function eeFields(string $buf): array {
    $s = new BinaryStream($buf, 1);
    return ['eid' => $s->getLong(), 'event' => $s->getByte()];
}

/**
 * SetEntityDataPacket: eid (long) + metadata blob (legacy writeMetadata
 * format: per entry a (type<<5)|key byte + payload, terminated by 0x7f).
 * Only the int/byte types we emit are parsed; the rest stops the scan.
 */
function sedFields(string $buf): array {
    $s = new BinaryStream($buf, 1);
    $eid = $s->getLong();
    $rest = substr($s->getBuffer(), $s->getOffset());
    $meta = [];
    $i = 0;
    $len = strlen($rest);
    while ($i < $len) {
        $b = ord($rest[$i]);
        if ($b === 0x7f) {
            break; // terminator
        }
        $key = $b & 0x1F;
        $type = $b >> 5;
        $i++;
        if ($type === 2) { // DATA_TYPE_INT
            $meta[$key] = \pocketmine\utils\Binary::readLInt(substr($rest, $i, 4));
            $i += 4;
        } elseif ($type === 0) { // DATA_TYPE_BYTE
            $meta[$key] = \pocketmine\utils\Binary::readByte($rest[$i]);
            $i += 1;
        } else {
            break;
        }
    }
    return ['eid' => $eid, 'meta' => $meta];
}

// Boot against a pristine world: region files persist in worlds/ across runs,
// and a stale chunk (from an older generator/seed) would fail the terrain
// consistency assertions below. Remove them so every run regenerates.
$worldsDir = dirname(__DIR__) . '/worlds';
if (is_dir($worldsDir)) {
    exec('rm -rf ' . escapeshellarg($worldsDir));
}

$port = 20000 + random_int(0, 20000);
$kernel = \pocketmine\bootstrap();
$kernel->setNetworkingEnabled(true);
$kernel->setBindPort($port);
$kernel->setAutoShutdownOnRun(false);

// 14.3: the builtin MobSpawnerSystem would otherwise populate hostile mobs
// around Alice every 40 ticks, making her health/position non-deterministic
// for the assertions below (the mob spawner has its own dedicated test).
$worldCfg = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ServerConfig::class);
if ($worldCfg instanceof \pocketmine\core\resource\ServerConfig) {
    $worldCfg->spawnMobs = false;
}

$kernel->run(1); // bind socket + start the RakNet thread + first tick

$client = new FakeClient($port);

// --- RakNet handshake (offline + connected) --------------------------------
test('real RakNet handshake completes (ping->pong, req1/2->reply1/2, connected)', function () use ($client, $kernel): void {
    $client->handshake(fn() => $kernel->run(1));
    $client->connect(fn() => $kernel->run(1));
});

// --- Login flow ------------------------------------------------------------
$uuidA = '11111111-2222-3333-4444-555555555555';
$loginPackets = [];
$startGame = null;
$chunkPayload = null;
$sawSpawn = false; // shared with the chunk-streaming test (spawn fires mid-burst)
$chunkCoords = []; // shared too: the login test may drain the first chunks
$spawnChunk = [0, 0]; // resolved safe-spawn chunk, set by the login test

test('login produces the full protocol-84 burst', function () use ($client, $kernel, $uuidA, &$loginPackets, &$startGame, &$sawSpawn, &$chunkCoords, &$spawnChunk): void {
    $client->sendLogin('Alice', $uuidA);
    $kernel->run(2);

    // Gather packets until the burst essentials are all seen (9 distinct
    // ids: the inventory content packet joined the login burst in 14.1).
    $deadline = microtime(true) + 8.0;
    $seen = [];
    while (microtime(true) < $deadline && count($seen) < 9) {
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            $seen[$id] = true;
            $loginPackets[] = [$id, $buffer];
            if ($id === Info::PLAY_STATUS_PACKET && psStatus($buffer) === PlayStatusPacket::PLAYER_SPAWN) {
                $sawSpawn = true;
            }
            if ($id === Info::FULL_CHUNK_DATA_PACKET) {
                $fc = fcFields($buffer);
                $chunkCoords[$fc['x'] . ',' . $fc['z']] = true;
            }
        }
        $kernel->run(1);
    }

    $byId = [];
    foreach ($loginPackets as [$id, $buffer]) {
        // Keep the FIRST packet per id: PLAYER_SPAWN (sent once chunk
        // streaming starts) would otherwise overwrite LOGIN_SUCCESS.
        $byId[$id] ??= $buffer;
    }

    ok(isset($byId[Info::PLAY_STATUS_PACKET]), 'play status sent');
    same(PlayStatusPacket::LOGIN_SUCCESS, psStatus($byId[Info::PLAY_STATUS_PACKET]), 'login success status');

    ok(isset($byId[Info::START_GAME_PACKET]), 'start game sent');
    $sg = sgFields($byId[Info::START_GAME_PACKET]);
    same(0, $sg['eid'], 'start game eid is 0 (protocol 84 self id)');
    same(0, $sg['gamemode'], 'survival gamemode');
    // Spawn is terrain-derived (safe spawn: nearest dry column, highest block
    // + 1), never the old fixed (0, 64, 0) that dropped the player inside a
    // hill and suffocated them - and never underwater when the configured
    // spawn lands in an ocean.
    ok(abs($sg['spawnX']) <= 24 && abs($sg['spawnZ']) <= 24, 'spawn stays near the configured world spawn');
    $store = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);
    $top = $store instanceof \pocketmine\core\resource\ChunkStore ? $store->getHighestBlockAt($sg['spawnX'], $sg['spawnZ']) : 64;
    // The spawn was resolved at boot against the freshly generated terrain;
    // allow the surface to have shifted a block by login time. The real
    // invariant is preserved: spawn is never INSIDE a block (the suffocation
    // regression) and the dry-land checks below still run.
    ok($sg['spawnY'] >= $top + 1 && $sg['spawnY'] <= $top + 3, "spawn y {$sg['spawnY']} is just above the surface (top $top)");
    $spawnBlock = $store instanceof \pocketmine\core\resource\ChunkStore ? $store->getBlock($sg['spawnX'], $top, $sg['spawnZ']) : -1;
    ok($spawnBlock !== 8 && $top >= 62, "spawn stands on dry land (surface block $spawnBlock at y=$top)");
    $spawnChunk[0] = (int)floor($sg['spawnX'] / 16);
    $spawnChunk[1] = (int)floor($sg['spawnZ'] / 16);

    ok(isset($byId[Info::SET_TIME_PACKET]), 'set time sent');
    // 14.6: the login burst carries a real world-clock value (the day/night
    // cycle), not a hardcoded 0 - and it is a valid day value. The exact
    // number depends on how many ticks elapsed before the burst was queued.
    $burstTime = stTime($byId[Info::SET_TIME_PACKET]);
    ok($burstTime >= 0 && $burstTime < 24000, "set time is a valid day value ($burstTime)");
    ok(isset($byId[Info::SET_SPAWN_POSITION_PACKET]), 'set spawn sent');
    // SetSpawnPosition mirrors the same safe spawn the player was placed at.
    $ssp = sspFields($byId[Info::SET_SPAWN_POSITION_PACKET]);
    same($sg['spawnX'], $ssp['x'], 'spawn position x matches start game');
    same($sg['spawnY'], $ssp['y'], 'spawn position y matches start game');
    same($sg['spawnZ'], $ssp['z'], 'spawn position z matches start game');

    ok(isset($byId[Info::SET_HEALTH_PACKET]), 'set health sent');
    same(20, shFields($byId[Info::SET_HEALTH_PACKET]), 'health 20');

    ok(isset($byId[Info::SET_DIFFICULTY_PACKET]), 'set difficulty sent');
    same(1, sdFields($byId[Info::SET_DIFFICULTY_PACKET]), 'difficulty 1');

    ok(isset($byId[Info::ADVENTURE_SETTINGS_PACKET]), 'adventure settings sent');
    same(0x4E, advFields($byId[Info::ADVENTURE_SETTINGS_PACKET])['flags'], 'adventure flags (no pvp/pvm/pve + auto jump)');

    ok(isset($byId[Info::PLAYER_LIST_PACKET]), 'player list sent');
    $entries = plEntries($byId[Info::PLAYER_LIST_PACKET]);
    same(1, count($entries), 'player list has one entry');
    same('Alice', $entries[0]['name'], 'player list names the joiner');

    // 14.1: the login burst now carries the full inventory (window 0) so the
    // client renders the starter kit hotbar.
    ok(isset($byId[Info::CONTAINER_SET_CONTENT_PACKET]), 'inventory content sent on login');
    $csc = cscFields($byId[Info::CONTAINER_SET_CONTENT_PACKET]);
    same(0, $csc['windowid'], 'inventory window id 0');
    same(36, count($csc['slots']), '36 inventory slots');
    // Starter kit: planks in slot 0 (held), cobblestone in slot 1.
    same(5, $csc['slots'][0][0], 'slot 0 holds planks');
    same(32, $csc['slots'][0][1], '32 planks in slot 0');
    same(4, $csc['slots'][1][0], 'slot 1 holds cobblestone');
});

// --- Chunk streaming -------------------------------------------------------
test('chunks stream with valid protocol-84 payloads and player spawn fires', function () use ($client, $kernel, &$chunkPayload, &$sawSpawn, &$chunkCoords, &$spawnChunk): void {
    $deadline = microtime(true) + 8.0;
    $chunkPayload = null;
    while (microtime(true) < $deadline && ($chunkPayload === null || !$sawSpawn)) {
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::FULL_CHUNK_DATA_PACKET) {
                $fc = fcFields($buffer);
                $chunkCoords[$fc['x'] . ',' . $fc['z']] = true;
                // The login test may have drained the first chunk already, so
                // capture the first chunk payload we see and separately assert
                // that the spawn chunk (0,0) is in the streamed set.
                if ($chunkPayload === null) {
                    ok(abs($fc['x']) <= 2 && abs($fc['z']) <= 2, 'first chunk is in the spawn neighbourhood');
                    same(FullChunkDataPacket::ORDER_LAYERED, $fc['order'], 'layered chunk order');
                    $chunkPayload = $fc['data'];
                }
            } elseif ($id === Info::PLAY_STATUS_PACKET && psStatus($buffer) === PlayStatusPacket::PLAYER_SPAWN) {
                $sawSpawn = true;
            }
        }
        $kernel->run(1);
    }
    ok($chunkPayload !== null, 'at least one full chunk delivered');
    ok($sawSpawn, 'PLAYER_SPAWN sent after chunk streaming began');
    ok(isset($chunkCoords[$spawnChunk[0] . ',' . $spawnChunk[1]]), 'the resolved spawn chunk is in the streamed set');
});

test('chunk payload is well-formed terrain (id/data/light/heightmap/biomes/extra)', function () use (&$chunkPayload): void {
    ok($chunkPayload !== null, 'chunk payload available');
    if ($chunkPayload === null) {
        return;
    }
    same(8 * 4096 + 8 * 2048 * 3 + 256 + 1024 + 4, strlen($chunkPayload), 'chunk payload length');

    // Fresh terrain: block data nibbles zero, block light zero.
    $dataStart = 8 * 4096;
    $skyStart = $dataStart + 8 * 2048;
    $lightStart = $skyStart + 8 * 2048;
    for ($i = 0; $i < 8 * 2048; $i++) {
        if (ord($chunkPayload[$dataStart + $i]) !== 0x00) {
            ok(false, 'block data nibbles are zero');
            return;
        }
        if (ord($chunkPayload[$lightStart + $i]) !== 0x00) {
            ok(false, 'block light nibbles are zero');
            return;
        }
    }

    // The wire heightmap is top non-air Y + 1, so a land column's surface
    // block (grass) sits at heightAt - 1. The seed is random per boot, so the
    // spawn chunk can contain ocean; scan for a grass-covered land column
    // instead of assuming column (0,0) is dry.
    $col = null;
    for ($bz = 0; $bz < 16 && $col === null; $bz++) {
        for ($bx = 0; $bx < 16; $bx++) {
            $hh = heightAt($chunkPayload, $bx, $bz);
            if ($hh > 4 && blockIdAt($chunkPayload, $bx, $bz, $hh - 1) === 2) {
                $col = [$bx, $bz, $hh];
                break;
            }
        }
    }
    ok($col !== null, 'chunk contains a grass-covered land column');
    if ($col === null) {
        return;
    }
    [$cx, $cz, $h] = $col;
    same(2, blockIdAt($chunkPayload, $cx, $cz, $h - 1), "surface block at y=" . ($h - 1) . " is grass (2)");
    same(3, blockIdAt($chunkPayload, $cx, $cz, $h - 2), 'block below surface is dirt (3)');
    if ($h >= 6) {
        same(1, blockIdAt($chunkPayload, $cx, $cz, $h - 5), 'stone beneath dirt (1)');
    }
    same(0, blockIdAt($chunkPayload, $cx, $cz, $h), 'block above the surface is air');
    same(0, blockIdAt($chunkPayload, $cx, $cz, 127), 'top of world is air');

    // Sky light follows the height map: surface block lit, deep block dark.
    same(0xF, nibbleAt($chunkPayload, $skyStart, $cx, $cz, $h - 1), 'surface block is fully sky-lit');
    same(0xF, nibbleAt($chunkPayload, $skyStart, $cx, $cz, 127), 'open air is fully sky-lit');
    same(0x0, nibbleAt($chunkPayload, $skyStart, $cx, $cz, 0), 'underground is dark');
});

// --- Chunk radius ----------------------------------------------------------
test('chunk radius request is acknowledged', function () use ($client, $kernel): void {
    $radiusBody = chr(Info::REQUEST_CHUNK_RADIUS_PACKET) . pack('N', 3);
    $client->sendRawBuffer($radiusBody);
    $kernel->run(1);

    $deadline = microtime(true) + 3.0;
    while (microtime(true) < $deadline) {
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::CHUNK_RADIUS_UPDATED_PACKET) {
                same(3, crFields($buffer), 'radius ack matches request');
                return;
            }
        }
        $kernel->run(1);
    }
    ok(false, 'chunk radius ack received');
});

// --- Large view distance (regression) --------------------------------------
// A real client joins at view distance 8-12, so the server generates hundreds
// of chunks (~200KB resident each in the ChunkStore). This used to blow the
// 128M PHP CLI default at the first big chunk batch; bootstrap() now raises
// the floor to 512M (legacy PocketMine parity) so the stream must survive.
test('a large view distance request streams many chunks without exhausting memory', function () use ($client, $kernel): void {
    // Request the maximum radius (12) - what a real client does on join.
    $radiusBody = chr(Info::REQUEST_CHUNK_RADIUS_PACKET) . pack('N', 12);
    $client->sendRawBuffer($radiusBody);
    $kernel->run(1);

    $chunkCount = 0;
    $deadline = microtime(true) + 6.0;
    while (microtime(true) < $deadline && $chunkCount < 60) {
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::FULL_CHUNK_DATA_PACKET) {
                $chunkCount++;
            }
        }
        $kernel->run(1);
    }
    ok($chunkCount >= 60, "streamed $chunkCount chunks at radius 12 without OOM");
    ok(memory_get_usage(true) < 512 * 1024 * 1024, 'memory stays under the raised limit');
});

// --- Movement --------------------------------------------------------------
test('client movement is applied to the ECS entity', function () use ($client, $kernel): void {
    $move = new MovePlayerPacket();
    $move->eid = 0;
    $move->x = 12.5;
    $move->y = 65.0;
    $move->z = -3.25;
    $move->yaw = 90.0;
    $move->bodyYaw = 90.0;
    $move->pitch = 10.0;
    $move->mode = MovePlayerPacket::MODE_NORMAL;
    $move->onGround = true;
    $client->sendGamePacket($move);

    // The packet now travels client socket -> RakLib thread -> kernel, so a
    // single tick may not be enough: poll until the position lands.
    $deadline = microtime(true) + 3.0;
    while (microtime(true) < $deadline) {
        $kernel->run(1);
        $online = $kernel->getNetworkSessionService()->getOnlinePlayers();
        if (isset($online[0]) && abs($online[0]['x'] - 12.5) < 1e-6) {
            same(1, count($online), 'one online player');
            $alice = $online[0];
            same('Alice', $alice['username'], 'username');
            near(12.5, $alice['x'], 1e-6, 'x applied');
            near(65.0, $alice['y'], 1e-6, 'y applied');
            near(-3.25, $alice['z'], 1e-6, 'z applied');
            return;
        }
        usleep(10000);
    }
    ok(false, 'movement applied to the ECS entity');
});

// --- Block interaction (14.1) ----------------------------------------------
/**
 * Find a surface column near the world spawn that has an AIR cell to its
 * east (so both the break and place tests get deterministic targets). Returns
 * [blockX, blockY, blockZ] of the surface block. The spawn is terrain-derived
 * and seed-random per boot, so the search scans outward until it finds one.
 * @return array{0: int, 1: int, 2: int}
 */
function findSurfaceBlockNearSpawn(\pocketmine\Kernel $kernel): array {
    $store = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);
    $store = $store instanceof \pocketmine\core\resource\ChunkStore ? $store : null;
    $sea = \pocketmine\adapter\driven\worldgen\ParallelGeneratorAdapter::SEA_LEVEL;
    $water = \pocketmine\adapter\driven\worldgen\ParallelGeneratorAdapter::WATER_BLOCK;
    $config = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ServerConfig::class);
    $cx = $config instanceof \pocketmine\core\resource\ServerConfig ? $config->spawnX : 0;
    $cz = $config instanceof \pocketmine\core\resource\ServerConfig ? $config->spawnZ : 0;
    for ($r = 0; $r <= 32; $r += 4) {
        for ($dz = -$r; $dz <= $r; $dz += 2) {
            for ($dx = -$r; $dx <= $r; $dx += 2) {
                $x = $cx + $dx;
                $z = $cz + $dz;
                if ($store === null) {
                    continue;
                }
                $top = $store->getHighestBlockAt($x, $z);
                if ($top < $sea || $store->getBlock($x, $top, $z) === $water) {
                    continue; // underwater column
                }
                // The east neighbor must be air at the same height: the
                // placement test targets it (clicking the surface's east face).
                if ($store->getBlock($x + 1, $top, $z) === 0) {
                    return [$x, $top, $z];
                }
            }
        }
    }
    return [$cx, $sea + 1, $cz];
}

/**
 * Teleport Alice to stand on top of a surface block and wait for it to land.
 * Throws on timeout so a stuck teleport fails the test with a clear cause
 * instead of silently continuing with Alice somewhere else.
 */
function teleportAliceOnto(\pocketmine\Kernel $kernel, FakeClient $client, int $x, int $y, int $z): void {
    $move = new MovePlayerPacket();
    $move->eid = 0;
    $move->x = $x + 0.5;
    $move->y = $y + 1;
    $move->z = $z + 0.5;
    $move->yaw = 0.0;
    $move->bodyYaw = 0.0;
    $move->pitch = 0.0;
    $move->mode = MovePlayerPacket::MODE_NORMAL;
    $move->onGround = true;
    $client->sendGamePacket($move);

    $deadline = microtime(true) + 3.0;
    while (microtime(true) < $deadline) {
        $kernel->run(1);
        $online = $kernel->getNetworkSessionService()->getOnlinePlayers();
        if (isset($online[0]) && abs($online[0]['x'] - ($x + 0.5)) < 1e-6) {
            return;
        }
        usleep(10000);
    }
    throw new RuntimeException('teleport to (' . $x . ', ' . $y . ', ' . $z . ') did not land');
}

test('held item change (MobEquipment) updates the ECS held slot', function () use ($client, $kernel): void {
    // Alice selects hotbar slot 1 (cobblestone from the starter kit).
    $me = new MobEquipmentPacket();
    $me->eid = 0;
    $me->item = [4, 32, 0, null];
    $me->slot = 1;
    $me->selectedSlot = 1;
    $client->sendGamePacket($me);

    $deadline = microtime(true) + 3.0;
    while (microtime(true) < $deadline) {
        $kernel->run(1);
        $online = $kernel->getNetworkSessionService()->getOnlinePlayers();
        if (isset($online[0])) {
            $entity = $kernel->getWorld()->getEntity($online[0]['entityId']);
            $inv = $entity?->get(\pocketmine\core\component\InventoryComponent::class);
            if ($inv !== null && $inv->heldSlot === 1) {
                same(1, $inv->heldSlot, 'held slot updated to 1');
                return;
            }
        }
        usleep(10000);
    }
    ok(false, 'held slot updated to 1');
});

test('breaking a block updates the world and broadcasts UpdateBlockPacket', function () use ($client, $kernel): void {
    [$bx, $by, $bz] = findSurfaceBlockNearSpawn($kernel);
    teleportAliceOnto($kernel, $client, $bx, $by, $bz);

    // Break the surface block under Alice's feet (ACTION_START_BREAK).
    $action = new PlayerActionPacket();
    $action->eid = 0;
    $action->action = PlayerActionPacket::ACTION_START_BREAK;
    $action->x = $bx;
    $action->y = $by;
    $action->z = $bz;
    $action->face = 1;
    $client->sendGamePacket($action);

    $store = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);
    $store = $store instanceof \pocketmine\core\resource\ChunkStore ? $store : null;

    $deadline = microtime(true) + 3.0;
    $sawUpdate = false;
    while (microtime(true) < $deadline && (!$sawUpdate || ($store !== null && $store->getBlock($bx, $by, $bz) !== 0))) {
        $kernel->run(1);
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::UPDATE_BLOCK_PACKET) {
                $ub = ubFields($buffer);
                if ($ub['x'] === $bx && $ub['y'] === $by && $ub['z'] === $bz && $ub['blockId'] === 0) {
                    $sawUpdate = true;
                }
            }
        }
        usleep(10000);
    }
    ok($store !== null && $store->getBlock($bx, $by, $bz) === 0, 'broken block is air in the world');
    ok($sawUpdate, 'UpdateBlockPacket broadcast for the broken block');
});

test('placing a block consumes inventory and broadcasts UpdateBlockPacket', function () use ($client, $kernel): void {
    // Ensure Alice holds planks (hotbar slot 0 of the starter kit).
    $me = new MobEquipmentPacket();
    $me->eid = 0;
    $me->item = [5, 32, 0, null];
    $me->slot = 0;
    $me->selectedSlot = 0;
    $client->sendGamePacket($me);
    $kernel->run(1);

    [$bx, $by, $bz] = findSurfaceBlockNearSpawn($kernel);
    teleportAliceOnto($kernel, $client, $bx, $by, $bz);

    // Place planks into the air cell east of the surface block: click the
    // surface block's east face (5).
    $use = new UseItemPacket();
    $use->x = $bx;
    $use->y = $by;
    $use->z = $bz;
    $use->face = 5; // east
    $use->fx = 0.0;
    $use->fy = 0.0;
    $use->fz = 0.0;
    $use->posX = $bx + 0.5;
    $use->posY = $by + 1.0;
    $use->posZ = $bz + 0.5;
    $use->slot = 0;
    $use->item = [5, 32, 0, null];
    $client->sendGamePacket($use);

    $store = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);
    $store = $store instanceof \pocketmine\core\resource\ChunkStore ? $store : null;

    $deadline = microtime(true) + 3.0;
    $sawUpdate = false;
    $sawSlot = false;
    while (microtime(true) < $deadline && (!$sawUpdate || !$sawSlot || ($store !== null && $store->getBlock($bx + 1, $by, $bz) !== 5))) {
        $kernel->run(1);
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::UPDATE_BLOCK_PACKET) {
                $ub = ubFields($buffer);
                if ($ub['x'] === $bx + 1 && $ub['y'] === $by && $ub['z'] === $bz && $ub['blockId'] === 5) {
                    $sawUpdate = true;
                }
            }
            if ($id === Info::CONTAINER_SET_SLOT_PACKET) {
                $css = cssFields($buffer);
                if ($css['slot'] === 0 && $css['item'][0] === 5 && $css['item'][1] === 31) {
                    $sawSlot = true; // one plank consumed
                }
            }
        }
        usleep(10000);
    }
    ok($store !== null && $store->getBlock($bx + 1, $by, $bz) === 5, 'placed block is planks in the world');
    ok($sawUpdate, 'UpdateBlockPacket broadcast for the placed block');
    ok($sawSlot, 'inventory slot synced back with 31 planks (one consumed)');
});

// --- Chat ------------------------------------------------------------------
test('chat is echoed back to the sender', function () use ($client, $kernel): void {
    $chat = new TextPacket();
    $chat->type = TextPacket::TYPE_CHAT;
    $chat->source = 'Alice';
    $chat->message = 'hello world';
    $client->sendGamePacket($chat);
    $kernel->run(1);

    $deadline = microtime(true) + 3.0;
    while (microtime(true) < $deadline) {
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::TEXT_PACKET) {
                $tp = textPacket($buffer);
                if ($tp['type'] === TextPacket::TYPE_RAW) {
                    same('Alice: hello world', $tp['message'], 'chat echo');
                    return;
                }
            }
        }
        $kernel->run(1);
    }
    ok(false, 'chat echo received');
});

// --- Second player ---------------------------------------------------------
test('a second client can join and sees the same world', function () use ($kernel, $port, $client): void {
    $client2 = new FakeClient($port);
    $client2->handshake(fn() => $kernel->run(1));
    $client2->connect(fn() => $kernel->run(1));

    $client2->sendLogin('Bob', '99999999-8888-7777-6666-555555555555');
    $kernel->run(2);

    $online = $kernel->getNetworkSessionService()->getOnlinePlayers();
    same(2, count($online), 'two online players');

    // Bob must receive the spawn burst too.
    $deadline = microtime(true) + 5.0;
    $bobGotStatus = false;
    $bobGotChunk = false;
    while (microtime(true) < $deadline && (!$bobGotStatus || !$bobGotChunk)) {
        foreach ($client2->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::PLAY_STATUS_PACKET && psStatus($buffer) === PlayStatusPacket::LOGIN_SUCCESS) {
                $bobGotStatus = true;
            }
            if ($id === Info::FULL_CHUNK_DATA_PACKET) {
                $bobGotChunk = true;
            }
        }
        $kernel->run(1);
    }
    ok($bobGotStatus, 'Bob received login success');
    ok($bobGotChunk, 'Bob received chunks');

    // Alice is notified of Bob via the player list.
    $deadline = microtime(true) + 3.0;
    $aliceSawBob = false;
    while (microtime(true) < $deadline && !$aliceSawBob) {
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::PLAYER_LIST_PACKET) {
                foreach (plEntries($buffer) as $entry) {
                    if (($entry['name'] ?? '') === 'Bob') {
                        $aliceSawBob = true;
                    }
                }
            }
        }
        $kernel->run(1);
    }
    ok($aliceSawBob, 'Alice sees Bob in the player list');

    // Bob's chat reaches Alice.
    $chat = new TextPacket();
    $chat->type = TextPacket::TYPE_CHAT;
    $chat->source = 'Bob';
    $chat->message = 'hi alice';
    $client2->sendGamePacket($chat);
    $kernel->run(1);

    $deadline = microtime(true) + 3.0;
    while (microtime(true) < $deadline) {
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::TEXT_PACKET && textPacket($buffer)['message'] === 'Bob: hi alice') {
                $client2->close();
                return;
            }
        }
        $kernel->run(1);
    }
    $client2->close();
    ok(false, 'Alice received Bob chat');
});

// --- Entity broadcasting (14.2) ---------------------------------------------
// A second player stays online for the whole section: it is the peer that
// Alice sees (and that sees Alice) as a wire entity.
$clientBob2 = null;
$bob2Eid = 0;

test('two players see each other as AddPlayerPacket entities', function () use ($kernel, $port, $client, &$clientBob2, &$bob2Eid): void {
    $clientBob2 = new FakeClient($port);
    $clientBob2->handshake(fn() => $kernel->run(1));
    $clientBob2->connect(fn() => $kernel->run(1));
    $clientBob2->sendLogin('Bob2', 'bbbbbbbb-aaaa-9999-8888-777777777777');
    $kernel->run(2);

    // Both directions: Bob2 receives Alice as an entity, Alice receives Bob2.
    $deadline = microtime(true) + 5.0;
    $bobSawAlice = false;
    $aliceSawBob = false;
    while (microtime(true) < $deadline && (!$bobSawAlice || !$aliceSawBob)) {
        foreach ($clientBob2->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::ADD_PLAYER_PACKET) {
                $ap = apFields($buffer);
                if ($ap['username'] === 'Alice') {
                    $bobSawAlice = true;
                    // Protocol 84 reserves eid 0 as the client's own self id;
                    // every OTHER player must have a real non-zero entity id.
                    ok($ap['eid'] !== 0, 'Alice broadcast with a non-zero entity id');
                }
            }
        }
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::ADD_PLAYER_PACKET) {
                $ap = apFields($buffer);
                if ($ap['username'] === 'Bob2') {
                    $aliceSawBob = true;
                    $bob2Eid = $ap['eid'];
                }
            }
        }
        $kernel->run(1);
    }
    ok($bobSawAlice, 'Bob sees Alice as an AddPlayerPacket entity');
    ok($aliceSawBob, 'Alice sees Bob as an AddPlayerPacket entity');
    ok($bob2Eid !== 0, 'Bob has a real (non-zero) entity id');
});

test('player movement is relayed to other players via MovePlayerPacket', function () use ($kernel, $client, &$clientBob2, &$bob2Eid): void {
    // Bob2 walks to a new spot (eid 0 is the client's own entity).
    $move = new MovePlayerPacket();
    $move->eid = 0;
    $move->x = 30.5;
    $move->y = 66.0;
    $move->z = 5.5;
    $move->yaw = 90.0;
    $move->bodyYaw = 90.0;
    $move->pitch = 0.0;
    $move->mode = MovePlayerPacket::MODE_NORMAL;
    $move->onGround = true;
    $clientBob2->sendGamePacket($move);

    // Drain BOTH sockets every iteration: Bob2 must keep ACKing the server's
    // frames or his reliable window fills and the server stalls (the relay to
    // Alice rides the same RakNet tick loop).
    $deadline = microtime(true) + 12.0;
    while (microtime(true) < $deadline) {
        $clientBob2->readGamePackets();
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::MOVE_PLAYER_PACKET) {
                $mp = mpFields($buffer);
                if ($mp['eid'] === $bob2Eid && abs($mp['x'] - 30.5) < 0.01) {
                    near(30.5, $mp['x'], 0.01, 'Alice sees Bob moved x');
                    near(5.5, $mp['z'], 0.01, 'Alice sees Bob moved z');
                    return;
                }
            }
        }
        $kernel->run(1);
    }
    ok(false, 'Alice received Bob move packet');
});

test('a spawned mob is broadcast as AddEntityPacket and followed with move/remove', function () use ($kernel, $client): void {
    // Alice's current position (the peer for the mob broadcast).
    $alice = null;
    foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
        if ($p['username'] === 'Alice') {
            $alice = $p;
        }
    }
    if ($alice === null) {
        ok(false, 'Alice is online');
        return;
    }

    // Spawn a zombie near Alice.
    $mob = $kernel->getEntitySpawnService()->spawnMob('Zombie', $alice['x'] + 3, $alice['y'] + 1, $alice['z']);
    $mobEid = $mob->getId();

    $deadline = microtime(true) + 5.0;
    $sawAdd = false;
    $metadataValid = false;
    while (microtime(true) < $deadline && !$sawAdd) {
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::ADD_ENTITY_PACKET) {
                $ae = aeFields($buffer);
                if ($ae['eid'] === $mobEid && $ae['type'] === 32) { // 32 = Zombie
                    $sawAdd = true;
                    // The 0.15 client renders a leash/rope on any entity that
                    // does not receive the legacy default data dict - notably
                    // DATA_LEAD_HOLDER (23, LONG -1) and DATA_LEAD (24, BYTE 0).
                    // Metadata starts at byte 49 (id + eid + type + 6 floats +
                    // 2 rotation floats + modifiers).
                    $meta = \pocketmine\utils\Binary::readMetadata(substr($buffer, 49));
                    $metadataValid = isset($meta[0])
                        && isset($meta[1])
                        && isset($meta[2])
                        && ($meta[23] ?? null) === -1
                        && ($meta[24] ?? null) === 0
                        && $meta[2] === 'Zombie';
                }
            }
        }
        $kernel->run(1);
    }
    ok($sawAdd, 'Alice received AddEntityPacket for the zombie');
    ok($metadataValid, 'zombie Add packet carries the legacy default metadata (flags/air/nametag/lead - no rope)');

    // Move the mob in the ECS and expect a MoveEntityPacket relay.
    $entity = $kernel->getWorld()->getEntity($mobEid);
    if ($entity === null) {
        ok(false, 'mob entity exists');
        return;
    }
    $pos = $entity->get(\pocketmine\core\component\PositionComponent::class);
    if ($pos !== null) {
        $pos->x += 5.0;
    }
    $kernel->run(1);

    $deadline = microtime(true) + 5.0;
    $sawMove = false;
    while (microtime(true) < $deadline && !$sawMove) {
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::MOVE_ENTITY_PACKET && meFields($buffer)['eid'] === $mobEid) {
                $sawMove = true;
            }
        }
        $kernel->run(1);
    }
    ok($sawMove, 'Alice received MoveEntityPacket for the moved zombie');

    // Despawn and expect RemoveEntityPacket.
    $kernel->getEntityDespawnService()->despawn($mob);
    $kernel->run(1);

    $deadline = microtime(true) + 5.0;
    $sawRemove = false;
    while (microtime(true) < $deadline && !$sawRemove) {
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::REMOVE_ENTITY_PACKET && reFields($buffer) === $mobEid) {
                $sawRemove = true;
            }
        }
        $kernel->run(1);
    }
    ok($sawRemove, 'Alice received RemoveEntityPacket for the despawned zombie');
});

test('a dropped item entity is broadcast as AddItemEntityPacket', function () use ($kernel, $client): void {
    $alice = null;
    foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
        if ($p['username'] === 'Alice') {
            $alice = $p;
        }
    }
    if ($alice === null) {
        ok(false, 'Alice is online');
        return;
    }

    // Drop 3 planks next to Alice.
    $item = $kernel->getEntitySpawnService()->spawnItem(
        $alice['x'] + 4,
        $alice['y'] + 1,
        $alice['z'],
        new \pocketmine\core\component\ItemStack(5, 0, 3),
    );
    $itemEid = $item->getId();

    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline) {
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::ADD_ITEM_ENTITY_PACKET) {
                $aie = aieFields($buffer);
                if ($aie['eid'] === $itemEid) {
                    same(5, $aie['item'][0], 'item id broadcast');
                    same(3, $aie['item'][1], 'item count broadcast');
                    $kernel->getEntityDespawnService()->despawn($item);
                    return;
                }
            }
        }
        $kernel->run(1);
    }
    $kernel->getEntityDespawnService()->despawn($item);
    ok(false, 'Alice received AddItemEntityPacket for the dropped item');
});

// --- Item pickup (14.5) ----------------------------------------------------
test('a dropped item is collected by walk-over and the inventory syncs back', function () use ($kernel, $client, &$clientBob2): void {
    $alice = null;
    foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
        if ($p['username'] === 'Alice') {
            $alice = $p;
        }
    }
    if ($alice === null) {
        ok(false, 'Alice is online');
        return;
    }

    // Drop 3 planks at Alice's feet: drops now fall with gravity + collision
    // (the floating-island terrain around spawn would otherwise let a
    // 1-block-away drop drift over a gap and fall out of reach). Zero the
    // spawn throw so the stack settles straight down onto the block she
    // stands on, well inside walk-over reach (1.5).
    $item = $kernel->getEntitySpawnService()->spawnItem(
        $alice['x'],
        $alice['y'],
        $alice['z'],
        new \pocketmine\core\component\ItemStack(5, 0, 3),
    );
    $itemEid = $item->getId();
    $itemVel = $kernel->getWorld()->getEntity($itemEid)?->get(\pocketmine\core\component\VelocityComponent::class);
    if ($itemVel !== null) {
        $itemVel->x = 0.0;
        $itemVel->z = 0.0;
    }

    // One loop waits for the whole wire lifecycle in order: the drop is
    // broadcast (AddItemEntityPacket), held by the fresh-drop pickup delay,
    // then collected by the per-tick walk-over system (RemoveEntityPacket +
    // the inventory sync back - slot 0 holds 31 planks, the starter 32 minus
    // the one consumed by the placement test, so +3 must make 34). Also pump
    // Bob2's client so his RakNet session cannot hit the 10s server-side idle
    // timeout during this test's polling.
    $deadline = microtime(true) + 8.0;
    $sawAdd = false;
    $sawRemove = false;
    $sawSlot = false;
    while (microtime(true) < $deadline && (!$sawAdd || !$sawRemove || !$sawSlot)) {
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::ADD_ITEM_ENTITY_PACKET && aieFields($buffer)['eid'] === $itemEid) {
                $sawAdd = true;
            }
            if ($id === Info::REMOVE_ENTITY_PACKET && reFields($buffer) === $itemEid) {
                $sawRemove = true;
            }
            if ($id === Info::CONTAINER_SET_CONTENT_PACKET) {
                $csc = cscFields($buffer);
                if (isset($csc['slots'][0]) && $csc['slots'][0][0] === 5 && $csc['slots'][0][1] === 34) {
                    $sawSlot = true;
                }
            }
        }
        if ($clientBob2 !== null) {
            $clientBob2->readGamePackets(); // keep Bob2's session alive
        }
        $kernel->run(1);
    }
    ok($sawAdd, 'Alice received AddItemEntityPacket for the dropped item');
    ok($sawRemove, 'Alice received RemoveEntityPacket for the collected item');
    ok($sawSlot, 'Alice inventory synced with the picked-up planks (31+3=34)');
});

// --- Combat (14.3) ---------------------------------------------------------
test('attacking a mob via InteractPacket damages it and broadcasts the hurt animation', function () use ($kernel, $client): void {
    $alice = null;
    foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
        if ($p['username'] === 'Alice') {
            $alice = $p;
        }
    }
    if ($alice === null) {
        ok(false, 'Alice is online');
        return;
    }

    // A zombie 2.5 blocks from Alice, at ground level: it settles on the
    // terrain (mobs have collision + gravity now) and stays inside the
    // 3-block attack reach of Alice's feet.
    $mob = $kernel->getEntitySpawnService()->spawnMob('Zombie', $alice['x'] + 2.5, $alice['y'] - 1.0, $alice['z']);
    $mobEid = $mob->getId();
    $mobEntity = $kernel->getWorld()->getEntity($mobEid);
    $healthBefore = $mobEntity?->get(\pocketmine\core\component\HealthComponent::class)?->current;
    ok($healthBefore !== null && $healthBefore > 0, 'mob alive before the attack');

    // Wait until Alice has the mob in her known-entity set (the Add packet
    // landed) so the following packets are the attack effects only.
    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline) {
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::ADD_ENTITY_PACKET && aeFields($buffer)['eid'] === $mobEid) {
                break 2;
            }
        }
        $kernel->run(1);
    }

    $interact = new \pocketmine\protocol\InteractPacket();
    $interact->action = \pocketmine\protocol\InteractPacket::ACTION_LEFT_CLICK;
    $interact->target = $mobEid;
    $client->sendGamePacket($interact);

    $deadline = microtime(true) + 5.0;
    $sawHurt = false;
    $sawAirCorruption = false;
    while (microtime(true) < $deadline && !$sawHurt) {
        $kernel->run(1);
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::ENTITY_EVENT_PACKET) {
                $ee = eeFields($buffer);
                if ($ee['eid'] === $mobEid && $ee['event'] === \pocketmine\protocol\EntityEventPacket::HURT_ANIMATION) {
                    $sawHurt = true;
                }
            }
            // Protocol 84 has no health metadata key: key 1 is DATA_AIR and
            // must never be written - the old health sync wrote it as an INT
            // and corrupted the entity's air (permanent drowning).
            if ($id === Info::SET_ENTITY_DATA_PACKET) {
                $sed = sedFields($buffer);
                if ($sed['eid'] === $mobEid && isset($sed['meta'][1])) {
                    $sawAirCorruption = true;
                }
            }
        }
        usleep(10000);
    }

    $healthAfter = $kernel->getWorld()->getEntity($mobEid)?->get(\pocketmine\core\component\HealthComponent::class)?->current;
    ok($healthAfter !== null && $healthAfter < $healthBefore, 'mob health dropped after the attack');
    ok($sawHurt, 'hurt animation broadcast via EntityEventPacket');
    ok(!$sawAirCorruption, 'no DATA_AIR corruption via SetEntityDataPacket health sync');
    $kernel->getEntityDespawnService()->despawn($mob);
});

test('a dead player respawns via RespawnPacket (health restored, spawn burst sent)', function () use ($kernel, $client): void {
    $alice = null;
    foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
        if ($p['username'] === 'Alice') {
            $alice = $p;
        }
    }
    if ($alice === null) {
        ok(false, 'Alice is online');
        return;
    }
    $aliceRef = \pocketmine\core\ecs\EntityRef::create($alice['entityId'], $kernel->getWorld());

    // Kill Alice through the combat pipeline: players stay in the world as
    // a dead corpse (DeadTag + 0 health) so respawn can revive them.
    $kernel->getCombatService()->kill($aliceRef);
    $kernel->run(1);
    $aliceHealth = $kernel->getWorld()->getEntity($alice['entityId'])?->get(\pocketmine\core\component\HealthComponent::class);
    ok($aliceHealth !== null && $aliceHealth->current === 0.0, 'Alice is dead (0 health)');

    // Drain any death-burst packets so the assertions below see only the
    // respawn response.
    $client->readGamePackets();

    $respawn = new \pocketmine\protocol\RespawnPacket();
    $respawn->x = 0.0;
    $respawn->y = 0.0;
    $respawn->z = 0.0;
    $client->sendGamePacket($respawn);

    $deadline = microtime(true) + 5.0;
    $sawSpawn = false;
    $sawHealth = false;
    $sawTeleport = false;
    while (microtime(true) < $deadline && (!$sawSpawn || !$sawHealth || !$sawTeleport)) {
        $kernel->run(1);
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::PLAY_STATUS_PACKET && psStatus($buffer) === PlayStatusPacket::PLAYER_SPAWN) {
                $sawSpawn = true;
            }
            if ($id === Info::SET_HEALTH_PACKET && shFields($buffer) === 20) {
                $sawHealth = true;
            }
            if ($id === Info::MOVE_PLAYER_PACKET) {
                $mp = mpFields($buffer);
                if ($mp['mode'] === MovePlayerPacket::MODE_RESET) {
                    $sawTeleport = true;
                }
            }
        }
        usleep(10000);
    }

    $aliceHealth = $kernel->getWorld()->getEntity($alice['entityId'])?->get(\pocketmine\core\component\HealthComponent::class);
    ok($aliceHealth !== null && $aliceHealth->current >= $aliceHealth->max, 'Alice is alive at full health after respawn');
    ok($sawSpawn, 'PLAYER_SPAWN status sent on respawn');
    ok($sawHealth, 'SetHealthPacket (20) sent on respawn');
    ok($sawTeleport, 'MovePlayerPacket teleport (MODE_RESET) sent on respawn');
});

// --- Protocol rejection ----------------------------------------------------
test('wrong protocol version is rejected with LOGIN_FAILED', function () use ($kernel, $port): void {
    $client3 = new FakeClient($port);
    $client3->handshake(fn() => $kernel->run(1));
    $client3->connect(fn() => $kernel->run(1));

    // Hand-rolled login buffer with protocol 999.
    $stream = new BinaryStream();
    $stream->putInt(999);
    $login = new LoginPacket();
    $login->setBuffer(chr(Info::LOGIN_PACKET) . $stream->getBuffer());
    $client3->sendGamePacket($login);
    $kernel->run(1);

    $deadline = microtime(true) + 3.0;
    while (microtime(true) < $deadline) {
        foreach ($client3->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::PLAY_STATUS_PACKET) {
                same(PlayStatusPacket::LOGIN_FAILED_SERVER, psStatus($buffer), 'server too old status');
                $client3->close();
                return;
            }
        }
        $kernel->run(1);
    }
    $client3->close();
    ok(false, 'login failed status received');
});

// --- Adapter layer sanity --------------------------------------------------
test('the adapter reports connected players after login', function () use ($kernel): void {
    $adapter = $kernel->getNetworkPort();
    ok($adapter instanceof Protocol84NetworkAdapter, 'adapter is protocol-84');
    /** @var Protocol84NetworkAdapter $adapter */
    ok($adapter->isRunning(), 'adapter socket running');
    // Alice is certainly connected (she is active throughout); Bob2 may have
    // idled past the RakNet 10s transport timeout under a loaded suite run.
    ok(count($adapter->getConnectedPlayers()) >= 1, 'adapter tracks connected players');
});

// --- Disconnect handling ---------------------------------------------------
test('a client that disconnects is removed from the session service', function () use ($kernel, $port): void {
    $d = new FakeClient($port);
    $d->handshake(fn() => $kernel->run(1));
    $d->connect(fn() => $kernel->run(1));
    $d->sendLogin('Dorian', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
    $kernel->run(2);

    // Dorian joined (transport-level open + login).
    $names = array_column($kernel->getNetworkSessionService()->getOnlinePlayers(), 'username');
    ok(in_array('Dorian', $names, true), 'Dorian joined');

    // Close the UDP socket: the RakNet session times out (10s) and the close
    // event must remove the game session. (Other clients' sessions may time
    // out around the same time, so only Dorian's removal is asserted.)
    $d->close();
    $deadline = microtime(true) + 14.0;
    while (microtime(true) < $deadline) {
        $kernel->run(1);
        $names = array_column($kernel->getNetworkSessionService()->getOnlinePlayers(), 'username');
        if (!in_array('Dorian', $names, true)) {
            ok(true, 'disconnected player removed');
            return;
        }
        usleep(50000);
    }
    ok(false, 'disconnected player removed within timeout');
});

// --- Player persistence over the wire (14.4b) ------------------------------
// A real client's data must survive a disconnect + reconnect: Rita picks up
// items (or has them placed server-side), disconnects cleanly (RakNet
// CLIENT_DISCONNECT -> leave service saves her), and her next login burst
// carries the restored inventory instead of the starter kit.
test('a disconnecting player is saved and a reconnect restores the inventory', function () use ($kernel, $port, $client): void {
    $ritaUuid = 'cccc3333-2222-3333-4444-555555555555';

    // Rita joins with her own client and waits for her login burst.
    $rita = new FakeClient($port);
    $rita->handshake(fn() => $kernel->run(1));
    $rita->connect(fn() => $kernel->run(1));
    $rita->sendLogin('Rita', $ritaUuid);
    $kernel->run(2);

    $deadline = microtime(true) + 5.0;
    $ritaId = 0;
    while (microtime(true) < $deadline && $ritaId === 0) {
        foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
            if ($p['username'] === 'Rita') {
                $ritaId = $p['entityId'];
            }
        }
        $kernel->run(1);
    }
    ok($ritaId !== 0, 'Rita joined');
    $rita->readGamePackets(); // drain her join burst

    // The stored uniqueId is the login uuid NORMALIZED by UUID::fromString
    // (variant nibble rewritten: '4444' -> '8444') - that is the id used for
    // the save file and the reconnect lookup, so capture it from her entity.
    $ritaSavedId = $kernel->getWorld()->getEntity($ritaId)?->get(\pocketmine\core\component\MetadataComponent::class)?->get('uniqueId');
    ok(is_string($ritaSavedId) && $ritaSavedId !== '', 'Rita entity carries a persisted uniqueId');

    // Server-side inventory change: slot 5 gets 3 iron ingots, held slot 5.
    $inv = $kernel->getWorld()->getEntity($ritaId)?->get(\pocketmine\core\component\InventoryComponent::class);
    $inv?->set(5, new \pocketmine\core\component\ItemStack(266, 0, 3));
    $inv?->setHeldSlot(5);

    // Clean RakNet disconnect: the server closes the session, which saves
    // Rita's data through the leave service.
    $rita->sendControl(chr(CLIENT_DISCONNECT_DataPacket::$ID));
    $deadline = microtime(true) + 5.0;
    $gone = false;
    while (microtime(true) < $deadline && !$gone) {
        $kernel->run(1);
        $client->readGamePackets(); // keep Alice's session alive
        $names = array_column($kernel->getNetworkSessionService()->getOnlinePlayers(), 'username');
        if (!in_array('Rita', $names, true)) {
            $gone = true;
        }
        usleep(20000);
    }
    ok($gone, 'Rita disconnected (session closed)');
    ok($ritaSavedId !== null && file_exists(dirname(__DIR__) . '/worlds/world/players/' . $ritaSavedId . '.dat'), 'Rita data file written on disconnect');

    // Reconnect with the same UUID: the login burst must carry her saved
    // inventory (slot 5 = 3 iron ingots), not the starter kit.
    $rita2 = new FakeClient($port);
    $rita2->handshake(fn() => $kernel->run(1));
    $rita2->connect(fn() => $kernel->run(1));
    $rita2->sendLogin('Rita', $ritaUuid);
    $kernel->run(2);

    $deadline = microtime(true) + 5.0;
    $sawRestored = false;
    while (microtime(true) < $deadline && !$sawRestored) {
        foreach ($rita2->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::CONTAINER_SET_CONTENT_PACKET) {
                $csc = cscFields($buffer);
                if (isset($csc['slots'][5]) && $csc['slots'][5][0] === 266 && $csc['slots'][5][1] === 3) {
                    $sawRestored = true;
                }
            }
        }
        $kernel->run(1);
    }
    ok($sawRestored, 'Rita reconnects with her saved inventory (3 iron ingots in slot 5)');
    $rita2->close();
});

// Teardown runs as a real test so it executes AFTER the other cases (the
// runner calls test fns in registration order).
test('the world clock advances and is broadcast each tick', function () use ($kernel, $port): void {
    // 14.6 day/night: the client must receive advancing SetTime packets so
    // the sun actually moves. Uses a FRESH client (earlier suite clients may
    // have idled past the RakNet 10s transport timeout) that logs in, then
    // waits for a SetTime whose value is LATER than the world clock at login.
    $c = new FakeClient($port);
    $c->handshake(fn() => $kernel->run(1));
    $c->connect(fn() => $kernel->run(1));
    $c->sendLogin('Terra', 'dddd3333-2222-3333-4444-666666666666');
    $kernel->run(2);

    // Let the login burst drain, then sample the clock AFTER login so the
    // starting SetTime (which may ride the burst) is not the only one seen.
    $c->readGamePackets();
    $worldConfig = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\WorldConfig::class);
    ok($worldConfig instanceof \pocketmine\core\resource\WorldConfig, 'world config present');
    if (!$worldConfig instanceof \pocketmine\core\resource\WorldConfig) {
        $c->close();
        return;
    }
    $t0 = $worldConfig->time;
    $kernel->run(5);
    $deadline = microtime(true) + 5.0;
    $latest = null;
    while (microtime(true) < $deadline) {
        foreach ($c->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::SET_TIME_PACKET) {
                $latest = max($latest ?? 0, stTime($buffer));
            }
        }
        if ($latest !== null && $latest > $t0) {
            break;
        }
        $kernel->run(1);
    }
    $c->close();
    ok($latest !== null, 'a SetTime broadcast arrives after login');
    ok($latest !== null && $latest > $t0, "time advances on the wire (t0=$t0, latest=" . ($latest ?? 'none') . ')');
});

test('server shuts down cleanly with active sessions', function () use ($kernel, $client): void {
    $adapter = $kernel->getNetworkPort();
    if ($adapter instanceof Protocol84NetworkAdapter) {
        foreach ($adapter->drainLogLines() as $line) {
            if (str_starts_with($line, 'critical')) {
                fwrite(STDERR, "RakLib thread error: $line\n");
            }
        }
    }
    $client->close();
    $kernel->shutdown();
    ok(true, 'shutdown completed');
});

exit(runTests());

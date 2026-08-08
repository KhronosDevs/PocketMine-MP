<?php

declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';
require __DIR__ . '/helpers.php';

use pocketmine\adapter\driven\network\Protocol84NetworkAdapter;
use pocketmine\protocol\BatchPacket;
use pocketmine\protocol\FullChunkDataPacket;
use pocketmine\protocol\Info;
use pocketmine\protocol\LoginPacket;
use pocketmine\protocol\MovePlayerPacket;
use pocketmine\protocol\PlayStatusPacket;
use pocketmine\protocol\RequestChunkRadiusPacket;
use pocketmine\protocol\TextPacket;
use pocketmine\utils\BinaryStream;
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
     * Wrap a raw game-packet body (id byte + fields) in a BatchPacket and
     * send it as a reliable-ordered encapsulated frame.
     */
    public function sendRawBuffer(string $buffer): void {
        $inner = pack('N', strlen($buffer)) . $buffer;
        $batch = new BatchPacket();
        $batch->payload = zlib_encode($inner, ZLIB_ENCODING_DEFLATE, 7);
        $batch->encode();
        $this->sendEncapsulated($batch->getBuffer(), PacketReliability::RELIABLE_ORDERED);
    }

    /** Send a game packet (encode + batch-wrap + reliable frame). */
    public function sendGamePacket(\pocketmine\protocol\DataPacket $packet): void {
        $packet->encode();
        $this->sendRawBuffer($packet->getBuffer());
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
            $this->gameBuffer[] = $buffer;
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

test('login produces the full protocol-84 burst', function () use ($client, $kernel, $uuidA, &$loginPackets, &$startGame, &$sawSpawn, &$chunkCoords): void {
    $client->sendLogin('Alice', $uuidA);
    $kernel->run(2);

    // Gather packets until the burst essentials are all seen.
    $deadline = microtime(true) + 5.0;
    $seen = [];
    while (microtime(true) < $deadline && count($seen) < 8) {
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
    same(0, $sg['spawnX'], 'spawn x');
    same(64, $sg['spawnY'], 'spawn y');
    same(0, $sg['spawnZ'], 'spawn z');
    same(0, $sg['gamemode'], 'survival gamemode');

    ok(isset($byId[Info::SET_TIME_PACKET]), 'set time sent');
    ok(isset($byId[Info::SET_SPAWN_POSITION_PACKET]), 'set spawn sent');
    same(64, sspFields($byId[Info::SET_SPAWN_POSITION_PACKET])['y'], 'spawn position y');

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
});

// --- Chunk streaming -------------------------------------------------------
test('chunks stream with valid protocol-84 payloads and player spawn fires', function () use ($client, $kernel, &$chunkPayload, &$sawSpawn, &$chunkCoords): void {
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
                    ok(abs($fc['x']) <= 1 && abs($fc['z']) <= 1, 'first chunk is in the spawn neighbourhood');
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
    ok(isset($chunkCoords['0,0']), 'spawn chunk (0,0) is in the streamed set');
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

    // Terrain exists: grass on dirt on stone under the height map.
    $h = heightAt($chunkPayload, 0, 0);
    ok($h > 3, "surface height $h is above bedrock");
    same(2, blockIdAt($chunkPayload, 0, 0, $h), "surface block at y=$h is grass (2)");
    same(3, blockIdAt($chunkPayload, 0, 0, $h - 1), 'block below surface is dirt (3)');
    if ($h >= 5) {
        same(1, blockIdAt($chunkPayload, 0, 0, $h - 4), 'stone beneath dirt (1)');
    }
    same(0, blockIdAt($chunkPayload, 0, 0, 127), 'top of world is air');

    // Sky light follows the height map: surface block lit, deep block dark.
    same(0xF, nibbleAt($chunkPayload, $skyStart, 0, 0, $h), 'surface block is fully sky-lit');
    same(0xF, nibbleAt($chunkPayload, $skyStart, 0, 0, 127), 'open air is fully sky-lit');
    same(0x0, nibbleAt($chunkPayload, $skyStart, 0, 0, 0), 'underground is dark');
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
    ok(count($adapter->getConnectedPlayers()) >= 2, 'adapter tracks connected players');
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

// Teardown runs as a real test so it executes AFTER the other cases (the
// runner calls test fns in registration order).
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

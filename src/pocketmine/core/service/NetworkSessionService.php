<?php

declare(strict_types=1);

namespace pocketmine\core\service;

use pocketmine\adapter\driven\network\Protocol84NetworkAdapter;
use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\RotationComponent;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\ResourceRegistry;
use pocketmine\core\ecs\World;
use pocketmine\core\resource\ServerConfig;
use pocketmine\port\driven\NetworkPort;
use pocketmine\port\driven\PlayerRef;
use pocketmine\protocol\AdventureSettingsPacket;
use pocketmine\protocol\BatchPacket;
use pocketmine\protocol\ChunkRadiusUpdatedPacket;
use pocketmine\protocol\ChunkSerializer;
use pocketmine\protocol\DataPacket;
use pocketmine\protocol\FullChunkDataPacket;
use pocketmine\protocol\Info;
use pocketmine\protocol\LoginPacket;
use pocketmine\protocol\MovePlayerPacket;
use pocketmine\protocol\PlayStatusPacket;
use pocketmine\protocol\PlayerListPacket;
use pocketmine\protocol\RequestChunkRadiusPacket;
use pocketmine\protocol\SetDifficultyPacket;
use pocketmine\protocol\SetHealthPacket;
use pocketmine\protocol\SetSpawnPositionPacket;
use pocketmine\protocol\SetTimePacket;
use pocketmine\protocol\StartGamePacket;
use pocketmine\protocol\TextPacket;
use pocketmine\utils\Binary;
use pocketmine\utils\UUID;
use function count;
use function floor;
use function in_array;
use function ord;
use function pack;
use function strlen;
use function substr;
use function usort;
use function zlib_decode;
use function zlib_encode;
use const ZLIB_ENCODING_DEFLATE;

/**
 * Game-session state machine for protocol 84 clients.
 *
 * Owns one logical "connection" per source address: it decodes inbound game
 * packets (batch-wrapped or raw), runs the login flow (play status -> start
 * game -> spawn -> chunk streaming), applies client movement to the ECS
 * entities, and echoes chat. All outbound game packets are wrapped in a
 * BatchPacket (zlib-deflated, length-prefixed) and flushed once per poll so a
 * burst of small packets rides a single datagram.
 *
 * This is the "game" half of networking; the adapter owns the RakLibServer
 * thread which speaks the RakNet wire protocol (handshake, framing,
 * reliability) and feeds decoded game packets here.
 */
final class NetworkSessionService {
    /** Max full chunks pushed to a client per poll (tick). */
    public const CHUNKS_PER_TICK = 2;
    private const DEFAULT_RADIUS = 4;
    private const MAX_RADIUS = 12;

    private ?Protocol84NetworkAdapter $adapter;
    private readonly World $world;
    private readonly PlayerJoinService $playerJoinService;
    private readonly PlayerLeaveService $playerLeaveService;
    private readonly ChunkLoadService $chunkLoadService;
    private readonly ResourceRegistry $resourceRegistry;

    /**
     * @var array<string, array{
     *   playerRef: PlayerRef,
     *   entityRef: EntityRef,
     *   username: string,
     *   uuid: UUID,
     *   radius: int,
     *   chunkQueue: list<array{0: int, 1: int}>,
     *   chunkQueueIndex: int,
     *   chunksSent: array<string, bool>,
     *   spawned: bool
     * }>
     */
    private array $sessions = [];
    /** @var array<string, list<DataPacket>> addrKey => packets awaiting this poll's flush */
    private array $outbound = [];

    public function __construct(
        NetworkPort $networkPort,
        World $world,
        PlayerJoinService $playerJoinService,
        PlayerLeaveService $playerLeaveService,
        ChunkLoadService $chunkLoadService,
        ResourceRegistry $resourceRegistry,
    ) {
        $this->adapter = $networkPort instanceof Protocol84NetworkAdapter ? $networkPort : null;
        $this->world = $world;
        $this->playerJoinService = $playerJoinService;
        $this->playerLeaveService = $playerLeaveService;
        $this->chunkLoadService = $chunkLoadService;
        $this->resourceRegistry = $resourceRegistry;
    }

    /**
     * Called once per kernel tick: drain inbound events, then stream any
     * pending chunks, then flush the outbound batch per session.
     */
    public function poll(): void {
        if ($this->adapter === null) {
            return;
        }
        foreach ($this->adapter->pollInboundEvents() as $event) {
            $this->handleInbound($event[0], $event[1], $event[2]);
        }
        foreach ($this->adapter->pollConnectionEvents() as $event) {
            if ($event[0] === 'close') {
                $this->handleSessionClosed($event[1], $event[2]);
            }
            // 'open' is transport-level only: the login game packet drives
            // session setup.
        }
        // Flush the response burst BEFORE streaming chunks: a full chunk is
        // large enough that it must ride its own datagram, and sharing a
        // batch with the burst would blow past the UDP payload ceiling.
        $this->flushOutbound();
        $this->streamChunks();
        $this->flushOutbound();
    }

    /**
     * A RakNet session closed (client disconnect, timeout, or kick): tear
     * down the game session and persist the player through the leave service.
     */
    private function handleSessionClosed(string $addrKey, string $reason): void {
        $session = $this->sessions[$addrKey] ?? null;
        if ($session === null) {
            return;
        }
        $this->playerLeaveService->handleDisconnect($session['playerRef'], $reason);
        unset($this->sessions[$addrKey], $this->outbound[$addrKey]);
        if ($this->adapter !== null) {
            $this->adapter->unregisterPlayer($session['playerRef']);
        }
    }

    public function shutdown(): void {
        if ($this->adapter !== null) {
            foreach ($this->sessions as $session) {
                $this->adapter->unregisterPlayer($session['playerRef']);
            }
        }
        $this->sessions = [];
        $this->outbound = [];
    }

    /**
     * Online players as plain arrays, for server introspection and tests.
     * @return list<array{username: string, entityId: int, x: float, y: float, z: float, chunksSent: int}>
     */
    public function getOnlinePlayers(): array {
        $out = [];
        foreach ($this->sessions as $session) {
            $pos = $session['entityRef']->getPosition();
            $out[] = [
                'username' => $session['username'],
                'entityId' => $session['playerRef']->entityId,
                'x' => $pos?->x ?? 0.0,
                'y' => $pos?->y ?? 0.0,
                'z' => $pos?->z ?? 0.0,
                'chunksSent' => count($session['chunksSent']),
            ];
        }
        return $out;
    }

    // --- Inbound -----------------------------------------------------------

    private function handleInbound(string $addrKey, int $packetId, string $buffer): void {
        if ($packetId === Info::BATCH_PACKET) {
            $batch = new BatchPacket();
            $batch->setBuffer($buffer, 1);
            $batch->decode();
            $payload = zlib_decode($batch->payload);
            if ($payload === false) {
                return;
            }
            $offset = 0;
            $len = strlen($payload);
            while ($offset + 4 <= $len) {
                $innerLen = Binary::readInt(substr($payload, $offset, 4));
                $offset += 4;
                if ($innerLen <= 0 || $offset + $innerLen > $len) {
                    break;
                }
                $this->handleGamePacket($addrKey, substr($payload, $offset, $innerLen));
                $offset += $innerLen;
            }
            return;
        }

        // The simplified transport also allows raw (unbatched) game datagrams.
        $this->handleGamePacket($addrKey, $buffer);
    }

    private function handleGamePacket(string $addrKey, string $buffer): void {
        $id = ord($buffer[0]);
        switch ($id) {
            case Info::LOGIN_PACKET:
                $pk = new LoginPacket();
                $pk->setBuffer($buffer, 1);
                $pk->decode();
                $this->handleLogin($addrKey, $pk);
                break;
            case Info::REQUEST_CHUNK_RADIUS_PACKET:
                $pk = new RequestChunkRadiusPacket();
                $pk->setBuffer($buffer, 1);
                $pk->decode();
                $this->handleChunkRadius($addrKey, $pk);
                break;
            case Info::MOVE_PLAYER_PACKET:
                $pk = new MovePlayerPacket();
                $pk->setBuffer($buffer, 1);
                $pk->decode();
                $this->handleMove($addrKey, $pk);
                break;
            case Info::TEXT_PACKET:
                $pk = new TextPacket();
                $pk->setBuffer($buffer, 1);
                $pk->decode();
                $this->handleChat($addrKey, $pk);
                break;
        }
    }

    private function handleLogin(string $addrKey, LoginPacket $pk): void {
        if (isset($this->sessions[$addrKey])) {
            return; // already logged in from this address
        }
        if (!in_array($pk->protocol, Info::ACCEPTED_PROTOCOLS, true)) {
            $status = $pk->protocol < Info::CURRENT_PROTOCOL
                ? PlayStatusPacket::LOGIN_FAILED_CLIENT
                : PlayStatusPacket::LOGIN_FAILED_SERVER;
            $playStatus = new PlayStatusPacket();
            $playStatus->status = $status;
            $this->sendDirectToAddress($addrKey, $playStatus);
            return;
        }

        $username = $pk->username !== '' ? $pk->username : 'Player';
        $uuid = $pk->clientUUID !== ''
            ? UUID::fromString($pk->clientUUID)
            : UUID::fromData($addrKey, (string)$pk->clientId);

        // Disambiguate duplicate usernames with a numeric suffix.
        $base = $username;
        $suffix = 1;
        while ($this->usernameTaken($username)) {
            $username = $base . $suffix++;
        }

        // Join first: the ECS entity (and its entity id) is created here.
        $entityRef = $this->playerJoinService->handleJoin(
            new PlayerRef($uuid->toString(), -1, $username),
            $username,
        );
        $playerRef = new PlayerRef($uuid->toString(), $entityRef->getId(), $username);
        $this->adapter->registerPlayer($addrKey, $playerRef);

        $this->sessions[$addrKey] = [
            'playerRef' => $playerRef,
            'entityRef' => $entityRef,
            'username' => $username,
            'uuid' => $uuid,
            'radius' => self::DEFAULT_RADIUS,
            'chunkQueue' => [],
            'chunkQueueIndex' => 0,
            'chunksSent' => [],
            'spawned' => false,
        ];

        // Broadcast the new player to everyone (including themselves).
        $this->broadcastPlayerListAdd($uuid, $playerRef->entityId, $username, $pk->skin ?? '');
        $this->sendLoginBurst($addrKey);
        $this->queueChunks($addrKey);
    }

    private function handleChunkRadius(string $addrKey, RequestChunkRadiusPacket $pk): void {
        $session = $this->sessions[$addrKey] ?? null;
        if ($session === null) {
            return;
        }
        $session['radius'] = max(2, min(self::MAX_RADIUS, $pk->radius));
        $this->sessions[$addrKey] = $session;

        $ack = new ChunkRadiusUpdatedPacket();
        $ack->radius = $session['radius'];
        $this->queuePacket($session['playerRef'], $ack);
        $this->queueChunks($addrKey);
    }

    private function handleMove(string $addrKey, MovePlayerPacket $pk): void {
        $session = $this->sessions[$addrKey] ?? null;
        if ($session === null) {
            return;
        }
        $entity = $session['entityRef']->getEntity();
        if ($entity === null) {
            return;
        }
        $pos = $entity->get(PositionComponent::class);
        if ($pos !== null) {
            $pos->x = $pk->x;
            $pos->y = $pk->y;
            $pos->z = $pk->z;
        }
        $rot = $entity->get(RotationComponent::class);
        if ($rot !== null) {
            $rot->yaw = $pk->yaw;
            $rot->pitch = $pk->pitch;
        }
    }

    private function handleChat(string $addrKey, TextPacket $pk): void {
        $session = $this->sessions[$addrKey] ?? null;
        if ($session === null) {
            return;
        }
        $echo = new TextPacket();
        $echo->type = TextPacket::TYPE_RAW;
        $echo->message = $session['username'] . ': ' . $pk->message;
        foreach ($this->sessions as $s) {
            $this->queuePacket($s['playerRef'], clone $echo);
        }
    }

    // --- Outbound ----------------------------------------------------------

    private function sendLoginBurst(string $addrKey): void {
        $session = $this->sessions[$addrKey];
        $playerRef = $session['playerRef'];
        $entity = $session['entityRef']->getEntity();
        $pos = $entity?->get(PositionComponent::class);
        $health = $entity?->get(HealthComponent::class);

        $spawnX = (int)floor($pos?->x ?? 0.0);
        $spawnY = (int)floor($pos?->y ?? 64.0);
        $spawnZ = (int)floor($pos?->z ?? 0.0);

        $config = $this->resourceRegistry->get(ServerConfig::class);

        $playStatus = new PlayStatusPacket();
        $playStatus->status = PlayStatusPacket::LOGIN_SUCCESS;
        $this->queuePacket($playerRef, $playStatus);

        $startGame = new StartGamePacket();
        $startGame->seed = $config instanceof ServerConfig ? $config->getSeed() : 0;
        $startGame->dimension = 0;
        $startGame->generator = 1;
        $startGame->gamemode = 0;
        $startGame->eid = 0; // protocol 84 always uses entity id 0 for the player
        $startGame->spawnX = $spawnX;
        $startGame->spawnY = $spawnY;
        $startGame->spawnZ = $spawnZ;
        $startGame->x = $pos?->x ?? 0.0;
        $startGame->y = $pos?->y ?? 64.0;
        $startGame->z = $pos?->z ?? 0.0;
        $this->queuePacket($playerRef, $startGame);

        $time = new SetTimePacket();
        $time->time = 0;
        $time->started = true;
        $this->queuePacket($playerRef, $time);

        $spawn = new SetSpawnPositionPacket();
        $spawn->x = $spawnX;
        $spawn->y = $spawnY;
        $spawn->z = $spawnZ;
        $this->queuePacket($playerRef, $spawn);

        $hp = new SetHealthPacket();
        $hp->health = (int)($health?->current ?? 20);
        $this->queuePacket($playerRef, $hp);

        $difficulty = new SetDifficultyPacket();
        $difficulty->difficulty = $config instanceof ServerConfig ? $config->difficulty : 1;
        $this->queuePacket($playerRef, $difficulty);

        $settings = new AdventureSettingsPacket();
        $settings->flags = 0x02 | 0x04 | 0x08 | 0x40; // no pvp/pvm/pve + auto jump
        $settings->userPermission = 2;
        $settings->globalPermission = 2;
        $this->queuePacket($playerRef, $settings);
    }

    private function broadcastPlayerListAdd(UUID $uuid, int $entityId, string $username, string $skin): void {
        $list = new PlayerListPacket();
        $list->type = PlayerListPacket::TYPE_ADD;
        $list->entries = [[$uuid, $entityId, $username, '0', $skin]];
        foreach ($this->sessions as $s) {
            $this->queuePacket($s['playerRef'], clone $list);
        }
    }

    private function queueChunks(string $addrKey): void {
        $session = $this->sessions[$addrKey];
        $pos = $session['entityRef']->getPosition();
        if ($pos === null) {
            return;
        }
        $centerX = (int)floor($pos->x / 16);
        $centerZ = (int)floor($pos->z / 16);
        $radius = $session['radius'];

        $list = [];
        for ($dx = -$radius; $dx <= $radius; $dx++) {
            for ($dz = -$radius; $dz <= $radius; $dz++) {
                $list[] = ['x' => $centerX + $dx, 'z' => $centerZ + $dz, 'd' => $dx * $dx + $dz * $dz];
            }
        }
        usort($list, static fn(array $a, array $b): int => $a['d'] <=> $b['d']);

        $session['chunkQueue'] = array_map(static fn(array $c): array => [$c['x'], $c['z']], $list);
        $session['chunkQueueIndex'] = 0;
        $this->sessions[$addrKey] = $session;
    }

    private function streamChunks(): void {
        foreach (array_keys($this->sessions) as $addrKey) {
            $session = $this->sessions[$addrKey];
            $sent = 0;
            while ($sent < self::CHUNKS_PER_TICK && $session['chunkQueueIndex'] < count($session['chunkQueue'])) {
                [$chunkX, $chunkZ] = $session['chunkQueue'][$session['chunkQueueIndex']];
                $session['chunkQueueIndex']++;
                $key = $chunkX . ',' . $chunkZ;
                if (isset($session['chunksSent'][$key])) {
                    continue;
                }
                $chunkData = $this->chunkLoadService->loadChunk($chunkX, $chunkZ);

                $chunk = new FullChunkDataPacket();
                $chunk->chunkX = $chunkX;
                $chunk->chunkZ = $chunkZ;
                $chunk->order = FullChunkDataPacket::ORDER_LAYERED;
                $chunk->data = ChunkSerializer::serialize($chunkData);
                $this->sendChunkBatch($session['playerRef'], $chunk);

                $session['chunksSent'][$key] = true;
                $sent++;
            }
            if (!$session['spawned'] && !empty($session['chunksSent'])) {
                $status = new PlayStatusPacket();
                $status->status = PlayStatusPacket::PLAYER_SPAWN;
                $this->queuePacket($session['playerRef'], $status);
                $session['spawned'] = true;
            }
            $this->sessions[$addrKey] = $session;
        }
    }

    private function flushOutbound(): void {
        if ($this->adapter === null) {
            return;
        }
        foreach ($this->outbound as $addrKey => $packets) {
            $session = $this->sessions[$addrKey] ?? null;
            if ($session === null) {
                continue;
            }
            $inner = '';
            foreach ($packets as $packet) {
                $packet->encode();
                $buffer = $packet->getBuffer();
                $inner .= pack('N', strlen($buffer)) . $buffer;
            }
            if ($inner === '') {
                continue;
            }
            $compressed = zlib_encode($inner, ZLIB_ENCODING_DEFLATE, 7);
            if ($compressed === false) {
                continue;
            }
            $batch = new BatchPacket();
            $batch->payload = $compressed;
            $batch->encode();
            $this->adapter->sendPacket($session['playerRef'], $batch);
        }
        $this->outbound = [];
    }

    /** Queue a packet for the next poll flush (batched). */
    private function queuePacket(PlayerRef $player, DataPacket $packet): void {
        $addrKey = $this->addrKeyForPlayer($player);
        if ($addrKey === null) {
            return;
        }
        $this->outbound[$addrKey][] = $packet;
    }

    /**
     * Send a full chunk as its own batch at max compression so it always
     * fits in a single UDP datagram, independent of the burst flush.
     */
    private function sendChunkBatch(PlayerRef $player, FullChunkDataPacket $chunk): void {
        if ($this->adapter === null) {
            return;
        }
        $chunk->encode();
        $buffer = $chunk->getBuffer();
        $inner = pack('N', strlen($buffer)) . $buffer;
        $compressed = zlib_encode($inner, ZLIB_ENCODING_DEFLATE, 9);
        if ($compressed === false) {
            return;
        }
        $batch = new BatchPacket();
        $batch->payload = $compressed;
        $batch->encode();
        $this->adapter->sendPacket($player, $batch);
    }

    /** Immediate (unbatched) send to an address, for pre-session replies. */
    private function sendDirectToAddress(string $addrKey, DataPacket $packet): void {
        if ($this->adapter === null) {
            return;
        }
        $packet->encode();
        $inner = pack('N', strlen($packet->getBuffer())) . $packet->getBuffer();
        $compressed = zlib_encode($inner, ZLIB_ENCODING_DEFLATE, 7);
        if ($compressed === false) {
            return;
        }
        $batch = new BatchPacket();
        $batch->payload = $compressed;
        $batch->encode();
        $this->adapter->sendRawPacket($addrKey, $batch);
    }

    private function addrKeyForPlayer(PlayerRef $player): ?string {
        foreach ($this->sessions as $addrKey => $session) {
            if ($session['playerRef']->entityId === $player->entityId) {
                return $addrKey;
            }
        }
        return null;
    }

    private function usernameTaken(string $username): bool {
        foreach ($this->sessions as $session) {
            if ($session['username'] === $username) {
                return true;
            }
        }
        return false;
    }
}

<?php

declare(strict_types=1);

namespace pocketmine\core\service;

use pocketmine\adapter\driven\network\Protocol84NetworkAdapter;
use pocketmine\api\command\PlayerCommandSender;
use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\HungerComponent;
use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\ItemStack;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\RotationComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\component\WorldComponent;
use pocketmine\core\constants\ItemIds;
use pocketmine\core\constants\MetadataKeys;
use pocketmine\core\ecs\Entity;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\ResourceRegistry;
use pocketmine\core\ecs\World;
use pocketmine\core\resource\ChestStore;
use pocketmine\core\resource\TileEntityStore;
use pocketmine\core\resource\Hunger;
use pocketmine\core\resource\ItemRegistry;
use pocketmine\core\resource\KhronosConfig;
use pocketmine\core\resource\ProjectileRegistry;
use pocketmine\core\resource\ChunkStore;
use pocketmine\core\resource\ServerConfig;
use pocketmine\core\resource\WorldConfig;
use pocketmine\core\enum\Difficulty;
use pocketmine\core\enum\EntityType;
use pocketmine\core\enum\GameMode;
use pocketmine\core\resource\WorldRegistry;
use pocketmine\port\driven\NetworkPort;
use pocketmine\port\driven\PlayerRef;
use pocketmine\port\driving\CommandPort;
use pocketmine\protocol\AddEntityPacket;
use pocketmine\protocol\AddItemEntityPacket;
use pocketmine\protocol\AddPlayerPacket;
use pocketmine\protocol\AdventureSettingsPacket;
use pocketmine\protocol\BatchPacket;
use pocketmine\protocol\BlockEntityDataPacket;
use pocketmine\protocol\ChunkRadiusUpdatedPacket;
use pocketmine\protocol\ChunkSerializer;
use pocketmine\protocol\ContainerClosePacket;
use pocketmine\protocol\ContainerOpenPacket;
use pocketmine\protocol\ContainerSetContentPacket;
use pocketmine\protocol\ContainerSetSlotPacket;
use pocketmine\protocol\CraftingDataPacket;
use pocketmine\protocol\CraftingEventPacket;
use pocketmine\protocol\DataPacket;
use pocketmine\protocol\DisconnectPacket;
use pocketmine\protocol\DropItemPacket;
use pocketmine\protocol\EntityEventPacket;
use pocketmine\protocol\FullChunkDataPacket;
use pocketmine\protocol\HurtArmorPacket;
use pocketmine\protocol\Info;
use pocketmine\protocol\InteractPacket;
use pocketmine\protocol\ItemFrameDropItemPacket;
use pocketmine\protocol\LevelEventPacket;
use pocketmine\protocol\LoginPacket;
use pocketmine\protocol\MobArmorEquipmentPacket;
use pocketmine\protocol\MobEquipmentPacket;
use pocketmine\protocol\MoveEntityPacket;
use pocketmine\protocol\MovePlayerPacket;
use pocketmine\protocol\PlayStatusPacket;
use pocketmine\protocol\PlayerActionPacket;
use pocketmine\protocol\PlayerListPacket;
use pocketmine\protocol\RemoveEntityPacket;
use pocketmine\protocol\RemoveBlockPacket;
use pocketmine\protocol\RequestChunkRadiusPacket;
use pocketmine\protocol\RespawnPacket;
use pocketmine\protocol\SetDifficultyPacket;
use pocketmine\protocol\SetEntityDataPacket;
use pocketmine\protocol\SetEntityLinkPacket;
use pocketmine\protocol\PlayerInputPacket;
use pocketmine\protocol\SetPlayerGameTypePacket;
use pocketmine\protocol\SetHealthPacket;
use pocketmine\protocol\SetSpawnPositionPacket;
use pocketmine\protocol\SetTimePacket;
use pocketmine\protocol\StartGamePacket;
use pocketmine\protocol\TextPacket;
use pocketmine\protocol\UpdateBlockPacket;
use pocketmine\protocol\UpdateAttributesPacket;
use pocketmine\protocol\UseItemPacket;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\utils\Binary;
use pocketmine\utils\UUID;
use function count;
use function floor;
use function in_array;
use function is_string;
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

    /**
     * Blocker 2 anti-cheat limits, read from khronos.json (KhronosConfig
     * resource) instead of hardcoded constants so admins can tune thresholds
     * and punishments without touching code. Baseline values (see
     * KhronosConfig): movement caps per tick generous enough for legit
     * sprint-jumps + packet latency (a sprint-jump peaks ~0.6 blocks/tick;
     * horizontal 1.2/tick = 24 m/s; ascent 0.8/tick, jump peak ~0.42; total
     * 2.0 covers the diagonal corner case) while making teleport/fly hacks
     * obvious; violations inside the window escalate from rubber-band to
     * kick; chat/command intervals are spam limits; login limits throttle
     * per-IP brute force.
     */
    private KhronosConfig $antiCheat;

    private ?Protocol84NetworkAdapter $adapter;
    private readonly World $world;
    private readonly PlayerJoinService $playerJoinService;
    private readonly PlayerLeaveService $playerLeaveService;
    private readonly ChunkLoadService $chunkLoadService;
    private readonly BlockBreakService $blockBreakService;
    private readonly BlockPlaceService $blockPlaceService;
    // 14.3: combat routing (attack via InteractPacket) and respawn handling
    // (RespawnPacket after death) reach the services directly.
    private readonly CombatService $combatService;
    private readonly PlayerRespawnService $playerRespawnService;
    private readonly EntityInteractionService $entityInteractionService;
    private readonly EntitySpawnService $entitySpawnService;
    private readonly CraftingService $craftingService;
    private readonly ResourceRegistry $resourceRegistry;
    private readonly CommandPort $commandPort;
    private readonly \pocketmine\port\driving\EventPort $eventPort;

    /**
     * MCPE block-face -> placement offset: the block a player places when
     * clicking face N of a block is the neighbor in this direction.
     */
    private const FACE_OFFSETS = [
        0 => [0, -1, 0], // down
        1 => [0, 1, 0],  // up
        2 => [0, 0, -1], // north
        3 => [0, 0, 1],  // south
        4 => [-1, 0, 0], // west
        5 => [1, 0, 0],  // east
    ];
    /** Trace game-layer packet handling while KHRONOS_WIRE_TRACE=1. */
    private bool $wireTrace = false;

    /**
     * @var array<string, array{
     *   playerRef: PlayerRef,
     *   entityRef: EntityRef,
     *   username: string,
     *   uuid: UUID,
     *   skin: string,
     *   worldId: int,
     *   radius: int,
     *   lastChunkX: int,
     *   lastChunkZ: int,
     *   chunkQueue: list<array{0: int, 1: int}>,
     *   chunkQueueIndex: int,
     *   chunksSent: array<string, bool>,
     *   spawned: bool,
     *   lastHealth: float,
     *   knownEntities: array<int, array{0: float, 1: float, 2: float, 3: float}>,
     *   moveCredit: array<string, int>,
     *   breaking: array{x: int, y: int, z: int, startTick: int}|null,
     *   openContainer: array{x: int, y: int, z: int, type: string, pair: array{x: int, y: int, z: int}|null}|null,
     *   bowDraw: int|null,
     *   lastWeather: int,
     *   lastMoveTick: int,
     *   moveViolations: int,
     *   moveViolationStartTick: int,
     *   teleportGraceTicks: int,
     *   lastChatAt: float,
     *   lastCommandAt: float
     * }>
     */
    private array $sessions = [];
    /** @var array<string, list<DataPacket>> addrKey => packets awaiting this poll's flush */
    private array $outbound = [];
    /** Blocker 2: ip => [count, windowStart] login attempts (throttle). */
    private array $loginAttempts = [];

    /** Movement packets are only re-sent when an entity moves this far. */
    private const MOVE_EPSILON = 0.01;

    public function __construct(
        NetworkPort $networkPort,
        World $world,
        PlayerJoinService $playerJoinService,
        PlayerLeaveService $playerLeaveService,
        ChunkLoadService $chunkLoadService,
        BlockBreakService $blockBreakService,
        BlockPlaceService $blockPlaceService,
        CombatService $combatService,
        PlayerRespawnService $playerRespawnService,
        EntityInteractionService $entityInteractionService,
        EntitySpawnService $entitySpawnService,
        CraftingService $craftingService,
        ResourceRegistry $resourceRegistry,
        CommandPort $commandPort,
        \pocketmine\port\driving\EventPort $eventPort,
    ) {
        $this->adapter = $networkPort instanceof Protocol84NetworkAdapter ? $networkPort : null;
        $this->world = $world;
        $this->playerJoinService = $playerJoinService;
        $this->playerLeaveService = $playerLeaveService;
        $this->chunkLoadService = $chunkLoadService;
        $this->blockBreakService = $blockBreakService;
        $this->blockPlaceService = $blockPlaceService;
        $this->combatService = $combatService;
        $this->playerRespawnService = $playerRespawnService;
        $this->entityInteractionService = $entityInteractionService;
        $this->entitySpawnService = $entitySpawnService;
        $this->craftingService = $craftingService;
        $this->resourceRegistry = $resourceRegistry;
        $this->commandPort = $commandPort;
        $this->eventPort = $eventPort;
        $this->wireTrace = getenv('KHRONOS_WIRE_TRACE') === '1';
        // The live KhronosConfig resource (defaults when a kernel was built
        // without bootstrap, the file-loaded instance otherwise). The service
        // keeps the reference, so mutating the resource at runtime (tests,
        // future /reload) is picked up immediately.
        $antiCheat = $resourceRegistry->get(\pocketmine\core\resource\KhronosConfig::class);
        $this->antiCheat = $antiCheat instanceof \pocketmine\core\resource\KhronosConfig
            ? $antiCheat
            : new \pocketmine\core\resource\KhronosConfig();
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
        // 14.6: every tick the client receives the current time of day so
        // the sun/moon keep moving (the login burst sends the starting value).
        $this->broadcastTime();
        // 14.22: weather transitions + periodic lightning during storms (the
        // login burst sends the starting weather state).
        $this->broadcastWeather();
        // 14.2: mirror live entities to every session (add/move/remove) so
        // other players, mobs and dropped items are visible on the wire.
        $this->broadcastEntityStates();
        // Flush the response burst BEFORE streaming chunks: a full chunk is
        // large enough that it must ride its own datagram, and sharing a
        // batch with the burst would blow past the UDP payload ceiling.
        $this->flushOutbound();
        $this->streamChunks();
        // 14.29: re-send chunks whose light changed since the last sync
        // (torch place/break, glowstone, TNT blast, ...) so the client's
        // light arrays follow the world. The store marks these during
        // recalculateLight; a full chunk re-send is the only way protocol 84
        // carries light to the client (UpdateBlockPacket has none).
        $this->flushLightUpdates();
        $this->flushOutbound();
    }

    /**
     * 14.29: re-send every chunk whose light arrays changed since the last
     * tick to sessions in the same world that already received it. The chunk
     * is freshly serialized so the payload carries the updated sky/block
     * light; sessions that never got the chunk skip it (their queue streams
     * the current version anyway).
     */
    private function flushLightUpdates(): void {
        if ($this->adapter === null) {
            return;
        }
        // Group sessions by world so each world's dirty set drains once.
        $byWorld = [];
        foreach ($this->sessions as $addrKey => $session) {
            $byWorld[$session['worldId']][] = $addrKey;
        }
        foreach ($byWorld as $worldId => $addrKeys) {
            $store = $this->getChunkStore((int)$worldId);
            if ($store === null) {
                continue;
            }
            foreach ($store->takeLightDirtyChunks() as [$chunkX, $chunkZ]) {
                $chunkData = $this->chunkLoadService->loadChunk($chunkX, $chunkZ, (int)$worldId);
                $chunk = new FullChunkDataPacket();
                $chunk->chunkX = $chunkX;
                $chunk->chunkZ = $chunkZ;
                $chunk->order = FullChunkDataPacket::ORDER_LAYERED;
                $chunk->data = ChunkSerializer::serialize($chunkData);
                foreach ($addrKeys as $addrKey) {
                    $key = $chunkX . ',' . $chunkZ;
                    if (isset($this->sessions[$addrKey]['chunksSent'][$key])) {
                        $this->sendChunkBatch($addrKey, clone $chunk);
                    }
                }
            }
        }
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
        // 14.25: a leaving rider is dismounted so the vehicle is freed for
        // the next player and never carries a dangling rider id.
        $this->dismountPlayer($addrKey);

        // 14.2: everyone else forgets the leaving player (entity + list entry).
        foreach ($this->sessions as $otherKey => $other) {
            if ($otherKey === $addrKey) {
                continue;
            }
            $rm = new RemoveEntityPacket();
            $rm->eid = $session['playerRef']->entityId;
            $this->queuePacket($other['playerRef'], $rm);
            $list = new PlayerListPacket();
            $list->type = PlayerListPacket::TYPE_REMOVE;
            $list->entries = [[$session['uuid']]];
            $this->queuePacket($other['playerRef'], $list);
            unset($other['knownEntities'][$session['playerRef']->entityId]);
            $this->sessions[$otherKey] = $other;
        }
        $this->playerLeaveService->handleDisconnect($session['playerRef'], $reason);
        unset($this->sessions[$addrKey], $this->outbound[$addrKey]);
        if ($this->adapter !== null) {
            $this->adapter->unregisterPlayer($session['playerRef']);
        }
    }

    /** Count established sessions connected from one IP host. */
    private function countSessionsForIp(string $host): int {
        $count = 0;
        foreach ($this->sessions as $addrKey => $session) {
            $h = str_contains($addrKey, ':') ? explode(':', $addrKey)[0] : $addrKey;
            if ($h === $host) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Kick one online player by entity id (ban command). The disconnect
     * reaches the client with a reason, the session closes and the player is
     * persisted through the leave service.
     */
    public function kick(int $entityId, string $reason = ''): bool {
        if ($this->adapter === null) {
            return false;
        }
        foreach ($this->sessions as $session) {
            if ($session['playerRef']->entityId === $entityId) {
                $this->adapter->disconnect($session['playerRef'], $reason !== '' ? $reason : 'Kicked');
                return true;
            }
        }
        return false;
    }

    /**
     * Kick every session connected from an IP address (ban-ip command).
     * @return int number of sessions kicked
     */
    public function kickByIp(string $ip, string $reason = ''): int {
        if ($this->adapter === null) {
            return 0;
        }
        $kicked = 0;
        foreach ($this->sessions as $addrKey => $session) {
            // addrKey is "ip:port" - match on the host part only.
            $host = str_contains($addrKey, ':') ? explode(':', $addrKey)[0] : $addrKey;
            if ($host === $ip) {
                $this->adapter->disconnect($session['playerRef'], $reason !== '' ? $reason : 'IP banned');
                $kicked++;
            }
        }
        return $kicked;
    }

    public function shutdown(): void {
        // 14.4b: players still online when the server stops (no clean
        // disconnect ever fired) are persisted here, BEFORE the sessions are
        // forgotten - the kernel's saveWorld loop sees an empty session list
        // after this returns.
        foreach ($this->sessions as $session) {
            $this->playerLeaveService->savePlayer($session['entityRef']);
        }
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

    /**
     * 14.20 multi-world: move a connected player to another world.
     *
     * The player entity's WorldComponent flips to the target world, the
     * session is re-pointed at that world's chunk stream/broadcasts, and the
     * player is teleported to the target world's spawn with a fresh chunk
     * queue. Returns false when the player is offline or the world id does
     * not exist.
     */
    public function switchWorld(int $entityId, int $worldId): bool {
        $registry = $this->resourceRegistry->get(WorldRegistry::class);
        if (!$registry instanceof WorldRegistry || $registry->getWorld($worldId) === null) {
            return false;
        }
        foreach ($this->sessions as $addrKey => $session) {
            if ($session['playerRef']->entityId !== $entityId) {
                continue;
            }
            // Flip the entity's world membership.
            $entity = $session['entityRef']->getEntity();
            $worldComponent = $entity?->get(WorldComponent::class);
            if ($worldComponent !== null) {
                $worldComponent->id = $worldId;
            }

            // Teleport to the target world's spawn.
            $config = $this->getWorldConfig($worldId);
            $spawnX = $config?->spawnX ?? 0;
            $spawnY = $config?->spawnY ?? 64;
            $spawnZ = $config?->spawnZ ?? 0;
            $pos = $entity?->get(PositionComponent::class);
            if ($pos !== null) {
                $pos->x = (float)$spawnX;
                $pos->y = (float)$spawnY;
                $pos->z = (float)$spawnZ;
            }
            $session['worldId'] = $worldId;
            $session['chunksSent'] = [];
            $session['chunkQueue'] = [];
            $session['chunkQueueIndex'] = 0;
            // Un-render every entity that was visible in the old world: a
            // client keeps drawing known entities until it is told to drop
            // them, so a world switch must explicitly remove them (a real
            // client would otherwise keep the old world's mobs on screen).
            foreach (array_keys($session['knownEntities']) as $oldEntityId) {
                $rm = new RemoveEntityPacket();
                $rm->eid = $oldEntityId;
                $this->queuePacket($session['playerRef'], $rm);
            }
            $session['knownEntities'] = [];
            // Stale per-action state from the old world must not survive the
            // switch (a half-broken block or open chest belongs to the old
            // world's coordinates).
            $session['breaking'] = null;
            $session['openContainer'] = null;
            $session['bowDraw'] = null;
            // The new world's weather must be pushed on the next poll (the
            // old world's state was already delivered to this client).
            $session['lastWeather'] = -1;
            // Blocker 2: the hard move below was produced server-side - grace
            // the client's converging moves so they are not flagged.
            $session['teleportGraceTicks'] = 3;
            $this->sessions[$addrKey] = $session;
            $this->queueChunks($addrKey);

            // The client needs the new world's time + spawn + a hard move.
            $time = new SetTimePacket();
            $time->time = $config?->time ?? 0;
            $time->started = true;
            $this->queuePacket($session['playerRef'], $time);
            $spawn = new SetSpawnPositionPacket();
            $spawn->x = $spawnX;
            $spawn->y = $spawnY;
            $spawn->z = $spawnZ;
            $this->queuePacket($session['playerRef'], $spawn);
            $move = new MovePlayerPacket();
            $move->eid = 0; // protocol 84: the player is always entity 0
            $move->x = (float)$spawnX;
            $move->y = (float)$spawnY;
            $move->z = (float)$spawnZ;
            $move->yaw = 0.0;
            $move->bodyYaw = 0.0;
            $move->pitch = 0.0;
            $move->mode = MovePlayerPacket::MODE_RESET;
            $this->queuePacket($session['playerRef'], $move);
            return true;
        }
        return false;
    }

    private function handleInbound(string $addrKey, int $packetId, string $buffer): void {
        // Protocol-84 wire (legacy RakLibInterface::getPacket): every game
        // frame is prefixed with a single 0xfe marker byte, then the packet
        // buffer proper (id byte + body) follows. The client sends either a
        // 0xfe-prefixed single packet or a 0xfe-prefixed compressed batch
        // (0x06) of length-prefixed packets. Drop only the marker so the
        // buffer still starts with the packet id byte.
        if ($packetId === 0xfe && strlen($buffer) >= 2) {
            $packetId = ord($buffer[1]);
            $buffer = substr($buffer, 1);
        }
        if ($packetId === Info::BATCH_PACKET) {
            $batch = new BatchPacket();
            $batch->setBuffer($buffer, 1);
            $batch->decode();
            // Cap the decompressed size like the legacy Network::processBatch
            // (64MB): a hostile frame must not be able to balloon memory.
            $payload = zlib_decode($batch->payload, 64 * 1024 * 1024);
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
        if ($this->wireTrace) {
            fwrite(STDERR, '[game] from ' . $addrKey . ' pid=0x'
                . str_pad(dechex($id), 2, '0', STR_PAD_LEFT) . ' len=' . strlen($buffer) . PHP_EOL);
        }
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
            case Info::PLAYER_ACTION_PACKET:
                $pk = new PlayerActionPacket();
                $pk->setBuffer($buffer, 1);
                $pk->decode();
                $this->handlePlayerAction($addrKey, $pk);
                break;
            case Info::PLAYER_INPUT_PACKET:
                $pk = new PlayerInputPacket();
                $pk->setBuffer($buffer, 1);
                $pk->decode();
                $this->handlePlayerInput($addrKey, $pk);
                break;
            case Info::USE_ITEM_PACKET:
                $pk = new UseItemPacket();
                $pk->setBuffer($buffer, 1);
                $pk->decode();
                $this->handleUseItem($addrKey, $pk);
                break;
            case Info::MOB_EQUIPMENT_PACKET:
                $pk = new MobEquipmentPacket();
                $pk->setBuffer($buffer, 1);
                $pk->decode();
                $this->handleMobEquipment($addrKey, $pk);
                break;
            case Info::CONTAINER_SET_SLOT_PACKET:
                $pk = new ContainerSetSlotPacket();
                $pk->setBuffer($buffer, 1);
                $pk->decode();
                $this->handleContainerSetSlot($addrKey, $pk);
                break;
            case Info::CONTAINER_CLOSE_PACKET:
                $pk = new ContainerClosePacket();
                $pk->setBuffer($buffer, 1);
                $pk->decode();
                $this->handleContainerClose($addrKey, $pk);
                break;
            case Info::DROP_ITEM_PACKET:
                $pk = new DropItemPacket();
                $pk->setBuffer($buffer, 1);
                $pk->decode();
                $this->handleDropItem($addrKey, $pk);
                break;
            case Info::REMOVE_BLOCK_PACKET:
                $pk = new RemoveBlockPacket();
                $pk->setBuffer($buffer, 1);
                $pk->decode();
                $this->handleRemoveBlock($addrKey, $pk);
                break;
            case Info::INTERACT_PACKET:
                $pk = new InteractPacket();
                $pk->setBuffer($buffer, 1);
                $pk->decode();
                $this->handleInteract($addrKey, $pk);
                break;
            case Info::RESPAWN_PACKET:
                $pk = new RespawnPacket();
                $pk->setBuffer($buffer, 1);
                $pk->decode();
                $this->handleRespawn($addrKey, $pk);
                break;
            case Info::CRAFTING_EVENT_PACKET:
                $pk = new CraftingEventPacket();
                $pk->setBuffer($buffer, 1);
                $pk->decode();
                $this->handleCraftingEvent($addrKey, $pk);
                break;
            case Info::BLOCK_ENTITY_DATA_PACKET:
                $pk = new BlockEntityDataPacket();
                $pk->setBuffer($buffer, 1);
                $pk->decode();
                $this->handleBlockEntityData($addrKey, $pk);
                break;
            case Info::ITEM_FRAME_DROP_ITEM_PACKET:
                $pk = new ItemFrameDropItemPacket();
                $pk->setBuffer($buffer, 1);
                $pk->decode();
                $this->handleItemFrameDrop($addrKey, $pk);
                break;
        }
    }

    private function handleLogin(string $addrKey, LoginPacket $pk): void {
        if ($this->wireTrace) {
            fwrite(STDERR, '[game] login from ' . $addrKey . ' protocol=' . $pk->protocol
                . ' user=' . $pk->username . ' uuid=' . $pk->clientUUID . PHP_EOL);
        }
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

        // Blocker 2: throttle login attempts per IP so a brute-forcer or a
        // stuck reconnecting client cannot flood the login pipeline.
        $host = str_contains($addrKey, ':') ? explode(':', $addrKey)[0] : $addrKey;
        $now = time();
        [$attempts, $windowStart] = $this->loginAttempts[$host] ?? [0, $now];
        if ($now - $windowStart >= 60) {
            $attempts = 0;
            $windowStart = $now;
        }
        $attempts++;
        $this->loginAttempts[$host] = [$attempts, $windowStart];
        if ($attempts > $this->antiCheat->loginAttemptsPerMinute) {
            $this->disconnectLogin($addrKey, 'Too many login attempts. Please try again later.');
            return;
        }
        // Hard cap on concurrent sessions per IP (NAT-burst protection).
        if ($this->countSessionsForIp($host) >= $this->antiCheat->maxSessionsPerIp) {
            $this->disconnectLogin($addrKey, 'Too many connections from your IP address.');
            return;
        }

        // Blocker 1/2: enforce the max-players cap (server.properties
        // max-players) before the ECS entity is created.
        $maxPlayers = $this->getWorldConfig(0)?->maxPlayers ?? 20;
        if (count($this->sessions) >= $maxPlayers) {
            $this->disconnectLogin($addrKey, 'The server is full. Try again later.');
            return;
        }

        // Blocker 1: enforce bans and the whitelist BEFORE the ECS entity is
        // created. A rejected login gets a DisconnectPacket and nothing else.
        $lists = $this->resourceRegistry->get(\pocketmine\core\resource\PlayerListManager::class);
        if ($lists instanceof \pocketmine\core\resource\PlayerListManager) {
            if ($lists->isBanned($pk->username) || $lists->isBanned($uuid->toString()) || $lists->isIpBanned($host)) {
                $this->disconnectLogin($addrKey, 'You have been banned from this server.');
                return;
            }
            $config = $this->resourceRegistry->get(\pocketmine\core\resource\ServerConfig::class);
            if (($config instanceof \pocketmine\core\resource\ServerConfig && $config->whiteList)
                && !$lists->isWhitelisted($pk->username) && !$lists->isWhitelisted($uuid->toString())) {
                $this->disconnectLogin($addrKey, 'You are not white-listed on this server.');
                return;
            }
        }

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
            'skin' => $pk->skin ?? '',
            // 14.20: players join the default world; /world switches it.
            'worldId' => 0,
            'radius' => self::DEFAULT_RADIUS,
            'lastChunkX' => 0,
            'lastChunkZ' => 0,
            'chunkQueue' => [],
            'chunkQueueIndex' => 0,
            'chunksSent' => [],
            'spawned' => false,
            'lastHealth' => $entityRef->getEntity()?->get(HealthComponent::class)?->current ?? 20.0,
            'knownEntities' => [],
            // Backing for validated inventory moves (id:meta => released count).
            'moveCredit' => [],
            // 14.8: in-progress block break (position + the tick it started).
            'breaking' => null,
            // 14.15: the chest this player currently has open (block coords).
            'openContainer' => null,
            // 14.17: the tick the player started charging a bow (null = not).
            'bowDraw' => null,
            // 14.22: the weather state last pushed to this client. The login
            // burst sends the current state explicitly, so tracking it here
            // means only actual transitions go out afterwards.
            'lastWeather' => $this->getWorldConfig(0)?->weather ?? 0,
            // Blocker 2 anti-cheat / spam state.
            'lastMoveTick' => $this->resourceRegistry->get(\pocketmine\core\resource\TickCounter::class)?->value ?? 0,
            'moveViolations' => 0,
            'moveViolationStartTick' => $this->resourceRegistry->get(\pocketmine\core\resource\TickCounter::class)?->value ?? 0,
            'teleportGraceTicks' => 0,
            'lastChatAt' => 0.0,
            'lastCommandAt' => 0.0,
        ];

        // Blocker 1: an ops.txt operator gets the op permission on their
        // entity so every permission check (builtin + plugin defaults) treats
        // them as an operator immediately, including mid-session op grants.
        if ($lists instanceof \pocketmine\core\resource\PlayerListManager) {
            if ($lists->isOp($uuid->toString()) || $lists->isOp($username)) {
                $meta = $entityRef->getEntity()?->get(\pocketmine\core\component\MetadataComponent::class);
                if ($meta !== null) {
                    $perms = (array)$meta->get('permissions', []);
                    if (!in_array('pocketmine.op', $perms, true)) {
                        $perms[] = 'pocketmine.op';
                        $meta->set(MetadataKeys::PERMISSIONS, $perms);
                    }
                }
            }
        }

        // Broadcast the new player to everyone (including themselves) and
        // hand the newcomer the list entries of everyone already online so
        // their skins/names render (they see the players as entities on the
        // next per-tick sync).
        $this->broadcastPlayerListAdd($uuid, $playerRef->entityId, $username, $pk->skin ?? '');
        $this->sendExistingPlayerList($addrKey);
        $this->sendLoginBurst($addrKey);
        $this->queueChunks($addrKey);
    }

    /** Reject a login with a DisconnectPacket (ban / whitelist / cap). */
    private function disconnectLogin(string $addrKey, string $reason): void {
        $packet = new DisconnectPacket();
        $packet->message = $reason;
        $packet->hideDisconnectionScreen = false;
        $this->sendDirectToAddress($addrKey, $packet);
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
        $rot = $entity->get(RotationComponent::class);

        // Blocker 2 anti-cheat: validate the claimed move against the last
        // server-known position BEFORE applying it. A too-fast move (speed /
        // teleport hack) or an airborne ascent without creative/allow-flight
        // is rejected: the client is rubber-banded back to the authoritative
        // position, and repeated violations end in a kick.
        if (!$this->validateMove($addrKey, $pk, $pos, $session)) {
            return;
        }
        // Persist the accepted move's bookkeeping (lastMoveTick / grace).
        $this->sessions[$addrKey] = $session;

        // The pre-move position, captured before the mutation below so the
        // event's from/to reflect the actual movement.
        $from = $pos !== null ? [$pos->x, $pos->y, $pos->z] : [0.0, 0.0, 0.0];
        if ($pos !== null) {
            $pos->x = $pk->x;
            $pos->y = $pk->y;
            $pos->z = $pk->z;
        }
        if ($rot !== null) {
            $rot->yaw = $pk->yaw;
            $rot->pitch = $pk->pitch;
        }

        // Blocker 4: PlayerMoveEvent (non-cancellable) fires after the move is
        // accepted so plugins observe the same authoritative position the
        // rest of the server sees.
        $this->eventPort->emit(new \pocketmine\api\event\PlayerMoveEvent(
            $this->wrapApiPlayer($session['entityRef']),
            $from,
            [$pk->x, $pk->y, $pk->z],
        ));

        // The world is infinite: queueChunks() only fires on login / radius
        // change / world switch, so a player who walks beyond the initially
        // streamed area would never see new chunks. Re-queue whenever the
        // player crosses into a new chunk column so the chunk stream follows
        // them (chunksSent already tracks what was delivered, so re-queueing
        // is idempotent - already-sent chunks are skipped by streamChunks).
        if ($pos !== null) {
            $chunkX = (int)floor($pos->x / 16);
            $chunkZ = (int)floor($pos->z / 16);
            if ($chunkX !== $session['lastChunkX'] || $chunkZ !== $session['lastChunkZ']) {
                $this->queueChunks($addrKey);
            }
        }
    }

    /**
     * Blocker 2: is this client move acceptable? The server-owned position is
     * authoritative; a move beyond the per-tick speed caps (teleport / speed
     * hacks) or an unallowed vertical ascent (fly hacks) is rejected. On
     * rejection the client is rubber-banded to the authoritative position and
     * a violation is recorded - max-violations inside the window kicks (both
     * configurable in khronos.json anti-cheat.movement).
     *
     * @param array<string, mixed> $session
     */
    private function validateMove(string $addrKey, MovePlayerPacket $pk, ?PositionComponent $pos, array &$session): bool {
        if ($pos === null) {
            return true;
        }
        // khronos.json anti-cheat.enabled=false turns movement validation off
        // entirely (a LAN/debug server that trusts its clients).
        if (!$this->antiCheat->antiCheatEnabled) {
            return true;
        }
        $tick = $this->resourceRegistry->get(\pocketmine\core\resource\TickCounter::class)?->value ?? 0;
        // After a server teleport the client's in-flight moves were produced
        // at the old position: grace a few ticks so they converge without
        // being flagged, then return to strict validation.
        if (($session['teleportGraceTicks'] ?? 0) > 0) {
            $session['teleportGraceTicks']--;
            $session['lastMoveTick'] = $tick;
            return true;
        }

        // Normalize by the ticks between accepted moves so a client that lags
        // (covers more distance in one packet) is not falsely flagged.
        $ticks = max(1, $tick - $session['lastMoveTick']);
        $session['lastMoveTick'] = $tick;

        $dx = $pk->x - $pos->x;
        $dy = $pk->y - $pos->y;
        $dz = $pk->z - $pos->z;
        $horizontal = sqrt($dx * $dx + $dz * $dz) / $ticks;
        $total = sqrt($dx * $dx + $dy * $dy + $dz * $dz) / $ticks;

        // Creative flight and the allow-flight property are the only legal
        // ways to ascend freely.
        $entity = $session['entityRef']->getEntity();
        $creative = GameMode::coerce($entity?->get(MetadataComponent::class)?->get(MetadataKeys::GAMEMODE)) === GameMode::Creative;
        $allowFlight = \pocketmine\api\server\Server::getInstance()->isAllowFlight();

        $violation = false;
        if ($total > $this->antiCheat->maxMoveTotalPerTick || $horizontal > $this->antiCheat->maxMoveHorizontalPerTick) {
            $violation = true; // speed / teleport hack
        } elseif ($dy > $this->antiCheat->maxMoveAscentPerTick * $ticks && !$creative && !$allowFlight) {
            $violation = true; // flying without permission
        }
        if (!$violation) {
            // Reset the violation counter on a clean streak.
            if ($session['moveViolations'] > 0 && $tick - $session['moveViolationStartTick'] > $this->antiCheat->moveViolationWindowTicks) {
                $session['moveViolations'] = 0;
            }
            return true;
        }

        // Reject: do not apply the move; snap the client back to the
        // authoritative position and count the violation. No teleport grace -
        // the next move is validated strictly so a repeat hack is still caught.
        // Both punishments (rubber-band, kick) are configurable in khronos.json.
        $session['moveViolations']++;
        $session['moveViolationStartTick'] = $tick;
        if ($this->antiCheat->rubberBandOnViolation) {
            $this->sendTeleportTo($session['playerRef']->entityId, $pos->x, $pos->y, $pos->z, false);
        }
        if ($this->antiCheat->kickOnViolations && $session['moveViolations'] >= $this->antiCheat->maxMoveViolations) {
            $this->kick($session['playerRef']->entityId, 'Movement speed exceeded');
        }
        $this->sessions[$addrKey] = $session;
        return false;
    }

    private function handleChat(string $addrKey, TextPacket $pk): void {
        $session = $this->sessions[$addrKey] ?? null;
        if ($session === null) {
            return;
        }
        // Blocker 2: oversized or too-frequent chat is dropped (spam limit;
        // thresholds from khronos.json anti-cheat.chat).
        $now = microtime(true);
        if (strlen($pk->message) > $this->antiCheat->maxChatLength
            || $now - $session['lastChatAt'] < $this->antiCheat->chatMinIntervalSeconds) {
            return;
        }
        $session['lastChatAt'] = $now;
        $this->sessions[$addrKey] = $session;

        // Commands: a leading '/' routes to the server-wide command map
        // instead of being broadcast as chat (legacy command handling; the
        // same map the console and plugins use, so builtins + plugin commands
        // share one registry). Command output goes back to the sender only.
        if (str_starts_with($pk->message, '/')) {
            $this->dispatchCommand($addrKey, substr($pk->message, 1));
            return;
        }

        // Blocker 4: cancellable PlayerChatEvent - plugins can block or
        // rewrite the message before it is broadcast.
        $event = new \pocketmine\api\event\PlayerChatEvent(
            $this->wrapApiPlayer($session['entityRef']),
            $pk->message,
        );
        $this->eventPort->emit($event);
        if ($event->isCancelled()) {
            return;
        }
        $message = $event->getMessage();

        $echo = new TextPacket();
        $echo->type = TextPacket::TYPE_RAW;
        $echo->message = $session['username'] . ': ' . $message;
        foreach ($this->sessions as $s) {
            $this->queuePacket($s['playerRef'], clone $echo);
        }
    }

    private function wrapApiPlayer(EntityRef $ref): \pocketmine\api\entity\Player {
        $entity = \pocketmine\api\entity\Entity::wrap($ref, $this->world);
        return $entity instanceof \pocketmine\api\entity\Player
            ? $entity
            : new \pocketmine\api\entity\Player($ref, $this->world);
    }

    private function dispatchCommand(string $addrKey, string $commandLine): void {
        $session = $this->sessions[$addrKey] ?? null;
        if ($session === null) {
            return;
        }
        // Blocker 2: command spam throttle (100ms between commands). A flood
        // of commands is dropped silently - the legitimate caller never
        // notices, the macro spammer gets nothing.
        $now = microtime(true);
        if ($now - $session['lastCommandAt'] < $this->antiCheat->commandMinIntervalSeconds) {
            return;
        }
        $session['lastCommandAt'] = $now;
        $this->sessions[$addrKey] = $session;

        $sender = new PlayerCommandSender(
            $session['playerRef']->entityId,
            $session['username'],
        );
        $this->commandPort->execute($sender, $commandLine);
    }

    /**
     * Send a raw chat/command-response line to one player (builtin + plugin
     * command output). Public because command senders resolve the session
     * service lazily via Kernel::getInstance().
     */
    public function sendMessageTo(int $entityId, string $message): void {
        foreach ($this->sessions as $session) {
            if ($session['playerRef']->entityId !== $entityId) {
                continue;
            }
            $pk = new TextPacket();
            $pk->type = TextPacket::TYPE_RAW;
            $pk->source = '';
            $pk->message = $message;
            $this->queuePacket($session['playerRef'], $pk);
            return;
        }
    }

    /**
     * The PlayerRef of the connected player with the given ECS entity id, or
     * null when they are not online (used by services that target a session
     * by entity).
     */
    public function getPlayerRefByEntity(int $entityId): ?\pocketmine\port\driven\PlayerRef {
        foreach ($this->sessions as $session) {
            if ($session['playerRef']->entityId === $entityId) {
                return $session['playerRef'];
            }
        }
        return null;
    }

    /**
     * Flip a player's client between survival and creative (AdventureSettings
     * flags; legacy parity for the 0.15 client). The authoritative gamemode
     * lives in MetadataComponent('gamemode') - this only mirrors it.
     */
    public function sendGamemodeTo(int $entityId, GameMode $mode): void {
        foreach ($this->sessions as $session) {
            if ($session['playerRef']->entityId !== $entityId) {
                continue;
            }
            // 0.15 protocol: AdventureSettingsPacket + SetPlayerGameTypePacket
            // both required to fully flip the client UI (hotbar, flight toggle,
            // block-breaking animation).
            $settings = new AdventureSettingsPacket();
            $settings->flags = $mode === GameMode::Creative ? AdventureSettingsPacket::FLAGS_CREATIVE : AdventureSettingsPacket::FLAGS_SURVIVAL;
            $settings->userPermission = 2;
            $settings->globalPermission = 2;
            $this->queuePacket($session['playerRef'], $settings);
            $typePk = new SetPlayerGameTypePacket();
            $typePk->gamemode = $mode->value;
            $this->queuePacket($session['playerRef'], $typePk);
            return;
        }
    }

    /**
     * Teleport a player: set the ECS position (so the per-tick entity
     * broadcast moves them for every viewer) and send an immediate
     * MovePlayerPacket (MODE_RESET) to the actor.
     */
    public function sendTeleportTo(int $entityId, float $x, float $y, float $z, bool $grace = true): void {
        foreach ($this->sessions as $addrKey => $session) {
            if ($session['playerRef']->entityId !== $entityId) {
                continue;
            }
            $entity = $session['entityRef']->getEntity();
            $pos = $entity?->get(PositionComponent::class);
            if ($pos === null) {
                return;
            }
            $pos->x = $x;
            $pos->y = $y;
            $pos->z = $z;
            $rotation = $entity->get(RotationComponent::class);
            $pk = new MovePlayerPacket();
            $pk->eid = 0; // protocol 84: the player's own entity is always 0
            $pk->x = $x;
            $pk->y = $y;
            $pk->z = $z;
            $pk->yaw = $rotation?->yaw ?? 0.0;
            $pk->bodyYaw = $rotation?->yaw ?? 0.0;
            $pk->pitch = $rotation?->pitch ?? 0.0;
            $pk->mode = MovePlayerPacket::MODE_RESET;
            $pk->onGround = true;
            $this->queuePacket($session['playerRef'], $pk);
            // Blocker 2: the client's in-flight move packets were produced at
            // the OLD position - grace the next few ticks so converging moves
            // are not misread as a teleport/speed hack. A rubber-band (grace
            // off) keeps strict validation so the next hack is still caught.
            if ($grace) {
                $session['teleportGraceTicks'] = 3;
            }
            // A far teleport must stream the new area (walking across a chunk
            // boundary re-queues in handleMove; a server teleport /tp must do
            // the same - queueChunks is idempotent, already-sent chunks are
            // skipped).
            $chunkX = (int)floor($pos->x / 16);
            $chunkZ = (int)floor($pos->z / 16);
            if ($chunkX !== $session['lastChunkX'] || $chunkZ !== $session['lastChunkZ']) {
                $session['lastChunkX'] = $chunkX;
                $session['lastChunkZ'] = $chunkZ;
                $this->queueChunks($addrKey);
            }
            $this->sessions[$addrKey] = $session;
            return;
        }
    }

    /**
     * Block interaction (14.1/14.8): survival mining is a two-phase handshake.
     *
     * The 0.15 client animates the crack locally (it knows block hardness
     * itself) and then confirms completion with REMOVE_BLOCK_PACKET (or
     * ACTION_STOP_BREAK). The server stays authoritative on the TIMING: it
     * records the tick ACTION_START_BREAK arrives and only honours the
     * completion once BlockBreakService::requiredBreakTicks() has elapsed.
     * A hacked client cannot insta-mine, because an immediate confirm is
     * rejected. ACTION_ABORT_BREAK cancels the in-progress break. Creative
     * mode and zero-hardness blocks still break instantly on START.
     */
    private function handlePlayerAction(string $addrKey, PlayerActionPacket $pk): void {
        $session = $this->sessions[$addrKey] ?? null;
        if ($session === null) {
            return;
        }
        // 14.17: releasing the use button fires a charged bow (legacy
        // ACTION_RELEASE_ITEM -> releaseUsingItem -> shootBow).
        if ($pk->action === PlayerActionPacket::ACTION_RELEASE_ITEM) {
            $this->releaseBow($addrKey, $session);
            return;
        }
        // Any other action cancels an in-progress bow draw (legacy resets
        // startAction after the action switch).
        if (($session['bowDraw'] ?? null) !== null) {
            $session['bowDraw'] = null;
            $this->sessions[$addrKey] = $session;
        }
        if ($pk->action === PlayerActionPacket::ACTION_START_BREAK) {
            $ticks = $this->blockBreakService->requiredBreakTicks($session['entityRef'], $pk->x, $pk->y, $pk->z);
            if ($ticks < 0) {
                return; // unreachable or unbreakable: nothing starts
            }
            if ($ticks === 0) {
                // Creative or instant-break block (torches, saplings, ...).
                $worldId = $session['worldId'];
                $containerType = $this->containerTypeAtBlock($pk->x, $pk->y, $pk->z, $worldId);
                if ($this->blockBreakService->breakBlock($session['entityRef'], $pk->x, $pk->y, $pk->z, $pk->face)) {
                    $this->broadcastBlockState($pk->x, $pk->y, $pk->z, $worldId);
                    if ($containerType !== null) {
                        $this->onContainerBroken($containerType, $pk->x, $pk->y, $pk->z, $session);
                    }
                }
                $session['breaking'] = null;
                $this->sessions[$addrKey] = $session;
                return;
            }
            $session['breaking'] = [
                'x' => $pk->x,
                'y' => $pk->y,
                'z' => $pk->z,
                'startTick' => $this->currentTick(),
            ];
            $this->sessions[$addrKey] = $session;
            return;
        }
        if ($pk->action === PlayerActionPacket::ACTION_ABORT_BREAK) {
            $session['breaking'] = null;
            $this->sessions[$addrKey] = $session;
            return;
        }
        if ($pk->action === PlayerActionPacket::ACTION_STOP_BREAK) {
            $this->finishBreak($addrKey, $session, $pk->x, $pk->y, $pk->z);
        }
    }

    /**
     * The 0.15 client's "the crack finished" signal (REMOVE_BLOCK_PACKET):
     * break the block only if the player actually held the button for
     * requiredBreakTicks(). Mirrors the ACTION_STOP_BREAK path.
     */
    private function handleRemoveBlock(string $addrKey, RemoveBlockPacket $pk): void {
        $session = $this->sessions[$addrKey] ?? null;
        if ($session === null) {
            return;
        }
        $this->finishBreak($addrKey, $session, $pk->x, $pk->y, $pk->z);
    }

    private function finishBreak(string $addrKey, array $session, int $x, int $y, int $z): void {
        $breaking = $session['breaking'];
        // Creative mode: the 0.15 client breaks instantly and sends only
        // REMOVE_BLOCK_PACKET (no crack animation, no ACTION_START_BREAK), so
        // there is never an in-progress break to time-check. Break directly.
        $creative = \pocketmine\core\enum\GameMode::coerce(
            $session['entityRef']->getEntity()?->get(MetadataComponent::class)?->get(MetadataKeys::GAMEMODE)
        ) === \pocketmine\core\enum\GameMode::Creative;
        if ($creative) {
            $this->breakBlockNow($addrKey, $session, $x, $y, $z);
            return;
        }
        if ($breaking === null || $breaking['x'] !== $x || $breaking['y'] !== $y || $breaking['z'] !== $z) {
            return; // no in-progress break on this block (or moved away)
        }
        $session['breaking'] = null;
        $this->sessions[$addrKey] = $session;
        $elapsed = $this->currentTick() - $breaking['startTick'];
        $required = $this->blockBreakService->requiredBreakTicks($session['entityRef'], $x, $y, $z);
        if ($required < 0 || $elapsed < $required) {
            return; // released early / hostile instant confirm: block stays
        }
        $this->breakBlockNow($addrKey, $session, $x, $y, $z);
    }

    /**
     * Shared tail of the break paths: remove the block, broadcast the new
     * state, and clean up any container/furnace tile at the position.
     */
    private function breakBlockNow(string $addrKey, array $session, int $x, int $y, int $z): void {
        $containerType = $this->containerTypeAtBlock($x, $y, $z, $session['worldId']);
        if ($this->blockBreakService->breakBlock($session['entityRef'], $x, $y, $z, 1)) {
            $this->broadcastBlockState($x, $y, $z, $session['worldId']);
            if ($containerType !== null) {
                $this->onContainerBroken($containerType, $x, $y, $z, $session);
            }
        }
    }

    /**
     * The container type of the block at a position, or null when it is not
     * a container block. Used by the break paths to spill + close windows.
     */
    private function containerTypeAtBlock(int $x, int $y, int $z, int $worldId = 0): ?string {
        $store = $this->getChunkStore($worldId);
        if ($store === null) {
            return null;
        }
        return match ($store->getBlock($x, $y, $z)) {
            54 => 'chest',
            61, 62 => 'furnace',
            23 => 'dispenser',
            154 => 'hopper',
            117 => 'brewing',
            default => null,
        };
    }

    /** Dispatch a broken container to its spill/close handler. */
    private function onContainerBroken(string $type, int $x, int $y, int $z, array $breaker): void {
        match ($type) {
            'chest' => $this->onChestBroken($x, $y, $z, $breaker),
            'furnace' => $this->onFurnaceBroken($x, $y, $z, $breaker),
            default => $this->onTileContainerBroken($type, $x, $y, $z, $breaker),
        };
    }

    private function currentTick(): int {
        $counter = $this->resourceRegistry->get(\pocketmine\core\resource\TickCounter::class);
        return $counter instanceof \pocketmine\core\resource\TickCounter ? $counter->value : 0;
    }

    /**
     * 14.17: ACTION_RELEASE_ITEM with a charged bow fires an arrow. Mirrors the
     * legacy Player::releaseUsingItem -> shootBow flow: force grows with charge
     * time (fully charged at 20 ticks = 1s), survival consumes one arrow and
     * wears the bow, creative needs no arrow and takes no durability.
     */
    private function releaseBow(string $addrKey, array $session): void {
        $bowDraw = $session['bowDraw'] ?? null;
        $session['bowDraw'] = null;
        $this->sessions[$addrKey] = $session;
        if ($bowDraw === null) {
            return; // no draw in progress
        }
        $entity = $session['entityRef']->getEntity();
        $inventory = $entity?->get(InventoryComponent::class);
        if ($inventory === null) {
            return;
        }
        $held = $inventory->get($inventory->heldSlot);
        if ($held === null || $held->itemId !== ItemIds::BOW || $held->count <= 0) {
            return; // no longer holding a bow
        }

        // Legacy charge: p = seconds charged, f = min((p^2 + 2p) / 3, 1) * 2.
        $diff = $this->currentTick() - $bowDraw;
        $p = $diff / 20;
        $f = min((($p ** 2) + $p * 2) / 3, 1) * 2;
        if ($f < 0.1 || $diff < 5) {
            return; // released too fast / barely drawn (legacy cancel)
        }

        $creative = GameMode::coerce($entity->get(MetadataComponent::class)?->get(MetadataKeys::GAMEMODE)) === GameMode::Creative;
        // Survival needs an arrow in the inventory (any meta, legacy ARROW).
        $arrowSlot = -1;
        if (!$creative) {
            foreach ($inventory->getContents() as $slot => $item) {
                if ($slot < InventoryComponent::ARMOR_OFFSET && $item->itemId === ItemIds::ARROW && $item->count > 0) {
                    $arrowSlot = $slot;
                    break;
                }
            }
            if ($arrowSlot === -1) {
                return; // no arrows
            }
        }

        $pos = $session['entityRef']->getPosition();
        $rot = $session['entityRef']->getRotation();
        if ($pos === null || $rot === null) {
            return;
        }
        // Legacy direction from yaw/pitch: x=-sin(yaw)cos(pitch), y=-sin(pitch),
        // z=cos(yaw)cos(pitch); motion = direction * force. Our velocity is in
        // blocks/second, so scale the per-tick force by 20.
        $yaw = deg2rad($rot->yaw);
        $pitch = deg2rad($rot->pitch);
        $dx = -sin($yaw) * cos($pitch);
        $dy = -sin($pitch);
        $dz = cos($yaw) * cos($pitch);
        $speed = $f * 20; // blocks/second

        $arrow = $this->entitySpawnService->spawnProjectile(
            EntityType::Arrow,
            $pos->x,
            $pos->y + 1.62, // eye height (legacy getEyeHeight())
            $pos->z,
            $dx * $speed,
            $dy * $speed,
            $dz * $speed,
            $session['entityRef'],
        );
        $arrowEntity = $arrow->getEntity();
        if ($arrowEntity) {
            $meta = $arrowEntity->get(MetadataComponent::class);
            if ($meta) {
                $meta->set(MetadataKeys::CRITICAL, $f >= 2.0);
            }
        }

        if (!$creative) {
            $inventory->remove($arrowSlot, 1);
            $this->sendInventorySlot($session['playerRef'], $arrowSlot);
            // Legacy: bow damage +1 per shot, breaks at max (384 uses).
            \pocketmine\core\resource\ItemDurability::consume($session['entityRef']);
        }
    }

    /**
     * Block interaction (14.1): USE_ITEM places the held block into the cell
     * adjacent to the clicked face (BlockPlaceService validates reach + the
     * player actually holds that block). On success the new block state is
     * broadcast and the consumed stack is reflected back to the actor's
     * inventory window so the client's hotbar count stays correct.
     */
    private function handleUseItem(string $addrKey, UseItemPacket $pk): void {
        $session = $this->sessions[$addrKey] ?? null;
        if ($session === null) {
            return;
        }
        $entity = $session['entityRef']->getEntity();
        $inventory = $entity?->get(InventoryComponent::class);
        if ($inventory === null) {
            return;
        }
        // 14.15: right-clicking a chest opens it (legacy Chest::onActivate
        // runs before placement in Level::useItemOn, and works with an empty
        // hand). The chest GUI is a real window: ContainerOpenPacket +
        // contents, then per-slot moves.
        $store = $this->getChunkStore($session['worldId']);
        if ($store !== null) {
            $block = $store->getBlock($pk->x, $pk->y, $pk->z);
            if ($block === ItemIds::CHEST) {
                $this->openChest($addrKey, $pk->x, $pk->y, $pk->z);
                return;
            }
            // 14.16: right-clicking a furnace (lit or unlit) opens its window.
            if ($block === 61 || $block === 62) {
                $this->openFurnace($addrKey, $pk->x, $pk->y, $pk->z);
                return;
            }
            // 14.27: right-clicking a dispenser (23) / hopper (154) / brewing
            // stand (117) opens its window.
            if ($block === 23) {
                $this->openDispenser($addrKey, $pk->x, $pk->y, $pk->z);
                return;
            }
            if ($block === 154) {
                $this->openHopper($addrKey, $pk->x, $pk->y, $pk->z);
                return;
            }
            if ($block === 117) {
                $this->openBrewing($addrKey, $pk->x, $pk->y, $pk->z);
                return;
            }
            // 14.24: right-clicking an item frame puts the held item into it
            // (or rotates an already-filled frame). Runs before placement like
            // the chest/furnace activation, and works with an empty hand.
            if ($block === 199) {
                $this->activateItemFrame($addrKey, $session, $pk->x, $pk->y, $pk->z);
                return;
            }
        }
        $held = $inventory->get($inventory->heldSlot);
        if ($held === null || $held->count <= 0) {
            return;
        }
        // 14.25: placing a boat (333) on water or a minecart (328) on a rail
        // spawns the vehicle entity and consumes the item (legacy Boat::
        // onActivate / Minecart::onActivate run before placement).
        if ($store !== null
            && ($held->itemId === ItemIds::BOAT || $held->itemId === ItemIds::MINECART)) {
            $targetId = $store->getBlock($pk->x, $pk->y, $pk->z);
            if ($held->itemId === ItemIds::BOAT && ($targetId === 8 || $targetId === 9)) {
                $this->spawnVehicleFromUse($addrKey, $session, $held, \pocketmine\core\enum\EntityType::Boat, $pk->x, $pk->y, $pk->z);
                return;
            }
            if ($held->itemId === ItemIds::MINECART && in_array($targetId, [27, 28, 66, 157], true)) {
                $this->spawnVehicleFromUse($addrKey, $session, $held, \pocketmine\core\enum\EntityType::Minecart, $pk->x, $pk->y, $pk->z);
                return;
            }
        }
        // 14.22: flint & steel on a TNT block primes it (legacy TNT::onActivate
        // runs before placement). The block becomes air and a lit PrimedTNT
        // entity spawns in its place; flint & steel loses durability.
        if ($held->itemId === ItemIds::FLINT_STEEL) {
            $store = $this->getChunkStore($session['worldId']);
            if ($store !== null && $store->getBlock($pk->x, $pk->y, $pk->z) === ItemIds::TNT) {
                $store->setBlock($pk->x, $pk->y, $pk->z, 0, 0);
                $kernel = \pocketmine\Kernel::getInstance();
                $spawn = $kernel?->getEntitySpawnService();
                if ($spawn !== null) {
                    $spawn->spawnPrimedTNT((float)$pk->x, (float)$pk->y, (float)$pk->z, $session['worldId']);
                }
                $this->broadcastBlockState($pk->x, $pk->y, $pk->z, $session['worldId']);
                \pocketmine\core\resource\ItemDurability::consume($session['entityRef']);
                return;
            }
        }
        // 14.17: a bow starts charging on use (legacy Player sets startAction
        // on USE_ITEM; the later ACTION_RELEASE_ITEM fires the arrow).
        if ($held->itemId === ItemIds::BOW) {
            $session['bowDraw'] = $this->currentTick();
            $this->sessions[$addrKey] = $session;
            return;
        }
        // 14.23: throwables - snowball (332), egg (344) and splash potion
        // (438) launch a projectile toward the player's facing (legacy
        // Player::useItem -> ProjectileItem::onActivate). Potions store their
        // item meta as POTION_ID so ArrowSystem can splash them on impact.
        if (in_array($held->itemId, [ItemIds::SNOWBALL, ItemIds::EGG, ItemIds::SPLASH_POTION], true)) {
            $this->throwItem($addrKey, $session, $held);
            return;
        }
        // 14.23: drinkable potion (373) applies its effect directly (legacy
        // Potion::onConsume); survival replaces it with a glass bottle (374).
        if ($held->itemId === ItemIds::POTION) {
            $this->drinkPotion($addrKey, $session, $held);
            return;
        }
        // 14.11: food items are eaten on use (legacy Food::onConsume). The
        // eat() path refuses when the player is already full.
        $registry = $this->resourceRegistry->get(ItemRegistry::class);
        $food = $registry instanceof ItemRegistry ? $registry->getFood($held->itemId) : null;
        if ($food !== null) {
            $this->eat($addrKey, $session, $held, $food);
            return;
        }
        $blockId = $held->itemId;
        // 14.24: a sign (323) or item frame (389) is placed as a block (63/68
        // sign, 199 frame) even though its item id is above 255 - the block id
        // only exists on the grid, not in the inventory.
        if ($blockId === ItemIds::SIGN || $blockId === ItemIds::ITEM_FRAME) {
            $this->placeTileEntityItem($addrKey, $session, $pk, $held);
            return;
        }
        // Item ids 1..255 are placeable blocks in the protocol-84 era; item
        // ids above that (tools, food...) are not placeable.
        if ($blockId <= 0 || $blockId > 255) {
            return;
        }
        [$dx, $dy, $dz] = self::FACE_OFFSETS[$pk->face] ?? self::FACE_OFFSETS[1];
        $targetX = $pk->x + $dx;
        $targetY = $pk->y + $dy;
        $targetZ = $pk->z + $dz;
        if ($this->blockPlaceService->placeBlock($session['entityRef'], $targetX, $targetY, $targetZ, $pk->face, $blockId, $held->meta)) {
            // Broadcast the AUTHORITATIVE state: placeBlock may resolve a
            // different meta internally (e.g. slab top/bottom from the face),
            // so the client must see exactly what the world now holds.
            $this->broadcastBlockState($targetX, $targetY, $targetZ, $session['worldId']);
            $this->sendInventorySlot($session['playerRef'], $inventory->heldSlot);
        }
    }

    /**
     * 14.24: the client finished editing a sign and reports the new text
     * (BLOCK_ENTITY_DATA_PACKET with little-endian NBT). Only the player who
     * placed the sign may edit it (legacy Sign::Creator check). The text is
     * stored in the per-world TileEntityStore and re-broadcast so every
     * viewer near the sign sees the update.
     */
    private function handleBlockEntityData(string $addrKey, BlockEntityDataPacket $pk): void {
        $session = $this->sessions[$addrKey] ?? null;
        if ($session === null) {
            return;
        }
        $store = $this->getChunkStore($session['worldId']);
        if ($store === null || $store->getBlock($pk->x, $pk->y, $pk->z) !== 63 && $store->getBlock($pk->x, $pk->y, $pk->z) !== 68) {
            return; // not a sign block
        }
        $nbt = new NBT(NBT::LITTLE_ENDIAN);
        $nbt->read($pk->namedtag);
        $data = $nbt->getData();
        if (!$data instanceof CompoundTag || $data->getTag('id')?->getValue() !== TileEntityStore::TILE_SIGN) {
            return;
        }
        $tiles = $this->tileEntityStore($session['worldId']);
        if ($tiles === null) {
            return;
        }
        $existing = $tiles->getSign($pk->x, $pk->y, $pk->z);
        // Only the placing player may edit (legacy Sign::Creator check). A
        // sign loaded from disk keeps its original creator string.
        $creator = $existing['creator'] ?? (string)$session['uuid'];
        if ($creator !== '' && $creator !== (string)$session['uuid']) {
            return;
        }
        $text = [];
        foreach (['Text1', 'Text2', 'Text3', 'Text4'] as $line) {
            $tag = $data->getTag($line);
            $text[] = $tag !== null ? (string)$tag->getValue() : '';
        }
        $tiles->setSign($pk->x, $pk->y, $pk->z, $text, (string)$session['uuid']);
        $this->broadcastTileEntity($pk->x, $pk->y, $pk->z, $session['worldId']);
    }

    /**
     * 14.24: the client right-clicked an item frame to remove its item
     * (ITEM_FRAME_DROP_ITEM_PACKET). The claimed slot is validated against
     * the authoritative frame state; on match the item drops as a normal
     * item entity, the frame clears and everyone nearby is refreshed.
     */
    private function handleItemFrameDrop(string $addrKey, ItemFrameDropItemPacket $pk): void {
        $session = $this->sessions[$addrKey] ?? null;
        if ($session === null) {
            return;
        }
        $tiles = $this->tileEntityStore($session['worldId']);
        if ($tiles === null) {
            return;
        }
        $frame = $tiles->getFrame($pk->x, $pk->y, $pk->z);
        $stored = $frame['item'] ?? null;
        $claimedId = (int)($pk->item[0] ?? 0);
        if ($stored === null || $stored['id'] !== $claimedId) {
            return; // hostile or stale claim - keep authoritative state
        }
        // Drop the real stored item (1), clear the frame, refresh viewers.
        $kernel = \pocketmine\Kernel::getInstance();
        $spawn = $kernel?->getEntitySpawnService();
        if ($spawn !== null) {
            $spawn->spawnItem((float)$pk->x + 0.5, (float)$pk->y + 0.5, (float)$pk->z + 0.5, new ItemStack($stored['id'], $stored['meta'], $stored['count']), $session['worldId']);
        }
        $tiles->clearFrame($pk->x, $pk->y, $pk->z);
        $this->broadcastTileEntity($pk->x, $pk->y, $pk->z, $session['worldId']);
    }

    /**
     * 14.24: right-clicking an item frame block. With an item in hand and an
     * empty frame, the held item (1) goes into the frame (survival consumes
     * it; creative keeps the stack). With a filled frame, the item rotates.
     */
    private function activateItemFrame(string $addrKey, array $session, int $x, int $y, int $z): void {
        $tiles = $this->tileEntityStore($session['worldId']);
        if ($tiles === null) {
            return;
        }
        $entity = $session['entityRef']->getEntity();
        $inventory = $entity?->get(InventoryComponent::class);
        $held = $inventory?->get($inventory->heldSlot);
        $frame = $tiles->getFrame($x, $y, $z);
        $hasItem = ($frame['item'] ?? null) !== null;
        if ($hasItem) {
            $tiles->rotateFrame($x, $y, $z);
        } elseif ($held !== null && $held->itemId > 0 && $held->count > 0) {
            $tiles->setFrameItem($x, $y, $z, $held);
            $metadata = $entity?->get(MetadataComponent::class);
            $creative = \pocketmine\core\enum\GameMode::coerce($metadata?->get(\pocketmine\core\constants\MetadataKeys::GAMEMODE)) === \pocketmine\core\enum\GameMode::Creative;
            if (!$creative && $inventory !== null) {
                $inventory->remove($inventory->heldSlot, 1);
                $this->sendInventorySlot($session['playerRef'], $inventory->heldSlot);
            }
        } else {
            return;
        }
        $this->broadcastTileEntity($x, $y, $z, $session['worldId']);
    }

    /**
     * 14.24: place a sign (item 323 -> block 63 post / 68 wall) or an item
     * frame (item 389 -> block 199) from the held item, then create its tile
     * entity. Sign placement follows legacy SignPost::place (wall faces become
     * a wall sign with the face's meta, everything else a post with the
     * player's yaw); frames need a side face.
     */
    private function placeTileEntityItem(string $addrKey, array $session, UseItemPacket $pk, ItemStack $held): void {
        $entity = $session['entityRef']->getEntity();
        $inventory = $entity?->get(InventoryComponent::class);
        if ($inventory === null) {
            return;
        }
        $store = $this->getChunkStore($session['worldId']);
        if ($store === null) {
            return;
        }
        [$dx, $dy, $dz] = self::FACE_OFFSETS[$pk->face] ?? self::FACE_OFFSETS[1];
        $tx = $pk->x + $dx;
        $ty = $pk->y + $dy;
        $tz = $pk->z + $dz;
        $existing = $store->getBlock($tx, $ty, $tz);
        if ($existing !== 0 && !$this->isReplaceableBlock($existing)) {
            return;
        }
        $metadata = $entity?->get(MetadataComponent::class);
        $creative = \pocketmine\core\enum\GameMode::coerce($metadata?->get(\pocketmine\core\constants\MetadataKeys::GAMEMODE)) === \pocketmine\core\enum\GameMode::Creative;

        if ($held->itemId === ItemIds::SIGN) {
            // Wall sign on a horizontal face (2-5), post otherwise (legacy
            // SignPost::place faces map).
            $wallFaces = [2 => 2, 3 => 3, 4 => 4, 5 => 5];
            if (isset($wallFaces[$pk->face])) {
                $store->setBlock($tx, $ty, $tz, 68, $wallFaces[$pk->face]);
            } else {
                $yaw = $session['entityRef']->getRotation()?->yaw ?? 0.0;
                $meta = (int)(floor((($yaw + 180) * 16 / 360) + 0.5)) & 0x0F;
                $store->setBlock($tx, $ty, $tz, 63, $meta);
            }
            $tiles = $this->tileEntityStore($session['worldId']);
            $tiles?->setSign($tx, $ty, $tz, ['', '', '', ''], (string)$session['uuid']);
            if (!$creative && $inventory !== null) {
                $inventory->remove($inventory->heldSlot, 1);
            }
            $this->broadcastBlockState($tx, $ty, $tz, $session['worldId']);
            $this->broadcastTileEntity($tx, $ty, $tz, $session['worldId']);
            $this->sendInventorySlot($session['playerRef'], $inventory->heldSlot);
            return;
        }
        // Item frame: only side faces (2-5), legacy ItemFrame::place faces map
        // (2=>3, 3=>2, 4=>1, 5=>0).
        $faces = [2 => 3, 3 => 2, 4 => 1, 5 => 0];
        if (!isset($faces[$pk->face])) {
            return;
        }
        $store->setBlock($tx, $ty, $tz, 199, $faces[$pk->face]);
        $tiles = $this->tileEntityStore($session['worldId']);
        $tiles?->clearFrame($tx, $ty, $tz);
        if (!$creative && $inventory !== null) {
            $inventory->remove($inventory->heldSlot, 1);
        }
        $this->broadcastBlockState($tx, $ty, $tz, $session['worldId']);
        $this->broadcastTileEntity($tx, $ty, $tz, $session['worldId']);
        $this->sendInventorySlot($session['playerRef'], $inventory->heldSlot);
    }

    /**
     * 14.25: spawn a vehicle from a held boat/minecart item used on the right
     * block (water for boats, rails for minecarts). Consumes one item in
     * survival; creative keeps the stack. The vehicle spawns centred on the
     * clicked block.
     */
    private function spawnVehicleFromUse(string $addrKey, array $session, ItemStack $held, \pocketmine\core\enum\EntityType $type, int $x, int $y, int $z): void {
        $entity = $session['entityRef']->getEntity();
        $metadata = $entity?->get(MetadataComponent::class);
        $creative = \pocketmine\core\enum\GameMode::coerce($metadata?->get(\pocketmine\core\constants\MetadataKeys::GAMEMODE)) === \pocketmine\core\enum\GameMode::Creative;
        $kernel = \pocketmine\Kernel::getInstance();
        $spawn = $kernel?->getEntitySpawnService();
        if ($spawn === null) {
            return;
        }
        $yaw = $session['entityRef']->getRotation()?->yaw ?? 0.0;
        $spawn->spawnVehicle($type, (float)$x, (float)$y, (float)$z, $session['worldId'], $yaw);
        if (!$creative) {
            $inventory = $entity?->get(InventoryComponent::class);
            if ($inventory !== null) {
                $inventory->remove($inventory->heldSlot, 1);
                $this->sendInventorySlot($session['playerRef'], $inventory->heldSlot);
            }
        }
    }

    /**
     * Held-item change: the client selected a different hotbar slot. Update
     * the ECS held slot (validated against the inventory size) and broadcast
     * the new held item to the OTHER players so their view of this player's
     * hands stays in sync.
     */
    private function handleMobEquipment(string $addrKey, MobEquipmentPacket $pk): void {
        $session = $this->sessions[$addrKey] ?? null;
        if ($session === null) {
            return;
        }
        $entity = $session['entityRef']->getEntity();
        $inventory = $entity?->get(InventoryComponent::class);
        if ($inventory === null || !$inventory->setHeldSlot($pk->selectedSlot)) {
            return;
        }
        $held = $inventory->get($inventory->heldSlot);
        foreach ($this->sessions as $otherKey => $s) {
            if ($otherKey === $addrKey) {
                continue; // the actor already knows their selection
            }
            $echo = new MobEquipmentPacket();
            $echo->eid = $session['playerRef']->entityId;
            $echo->item = $held !== null ? [$held->itemId, $held->count, $held->meta, $held->nbt] : [0, 0, 0, null];
            $echo->slot = $pk->selectedSlot;
            $echo->selectedSlot = $pk->selectedSlot;
            $this->queuePacket($s['playerRef'], $echo);
        }
    }

    /**
     * Inventory action (14.7): the client moved an item between slots of its
     * own inventory window (window 0) or armor window (0x78). Protocol 84's
     * player inventory has no separate source/destination fields - the client
     * sends the NEW contents of one slot per packet, so a drag produces
     * several of these.
     *
     * We apply the authoritative state directly (the client is the source of
     * truth for its own window layout) and mirror the change back to the
     * actor so the window stays in lockstep. Other container windows (chests,
     * furnaces, ...) are not wired yet.
     */
    private function handleContainerSetSlot(string $addrKey, ContainerSetSlotPacket $pk): void {
        $session = $this->sessions[$addrKey] ?? null;
        if ($session === null) {
            return;
        }
        $inventory = $session['entityRef']->getEntity()?->get(InventoryComponent::class);
        if ($inventory === null) {
            return;
        }

        // 14.13: translate the wire slot to an inventory slot. Window 0 is the
        // player's own inventory (0-35); the armor window (0x78) maps its 4
        // slots onto inventory slots 36-39 (helmet, chestplate, leggings,
        // boots). Move credit is session-wide, so a two-packet equip (empty
        // inventory slot A, fill armor slot B) is accepted across windows.
        $invSlot = -1;
        if ($pk->windowid === ContainerSetContentPacket::SPECIAL_INVENTORY) {
            if ($pk->slot >= 0 && $pk->slot < InventoryComponent::ARMOR_OFFSET) {
                $invSlot = $pk->slot;
            }
        } elseif ($pk->windowid === ContainerSetContentPacket::SPECIAL_ARMOR) {
            if ($pk->slot >= 0 && $pk->slot < 4) {
                $invSlot = InventoryComponent::ARMOR_OFFSET + $pk->slot;
            }
        } elseif ($pk->windowid === self::CHEST_WINDOW_ID) {
            $open = $session['openContainer'];
            if ($open !== null && $open['type'] === 'chest') {
                $size = $open['pair'] !== null ? ChestStore::CHEST_SIZE * 2 : ChestStore::CHEST_SIZE;
                if ($pk->slot >= 0 && $pk->slot < $size) {
                    $this->handleChestSetSlot($addrKey, $session, $pk);
                    return;
                }
            }
        } elseif ($pk->windowid === self::FURNACE_WINDOW_ID) {
            $open = $session['openContainer'];
            if ($open !== null && $open['type'] === 'furnace' && $pk->slot >= 0 && $pk->slot < \pocketmine\core\resource\FurnaceStore::SIZE) {
                $this->handleFurnaceSetSlot($addrKey, $session, $pk);
                return;
            }
        } elseif ($pk->windowid === self::DISPENSER_WINDOW_ID) {
            $open = $session['openContainer'];
            if ($open !== null && $open['type'] === 'dispenser' && $pk->slot >= 0 && $pk->slot < \pocketmine\core\resource\ContainerStore::DISPENSER_SIZE) {
                $this->handleTileContainerSetSlot($addrKey, $session, $pk);
                return;
            }
        } elseif ($pk->windowid === self::HOPPER_WINDOW_ID) {
            $open = $session['openContainer'];
            if ($open !== null && $open['type'] === 'hopper' && $pk->slot >= 0 && $pk->slot < \pocketmine\core\resource\ContainerStore::HOPPER_SIZE) {
                $this->handleTileContainerSetSlot($addrKey, $session, $pk);
                return;
            }
        } elseif ($pk->windowid === self::BREWING_WINDOW_ID) {
            $open = $session['openContainer'];
            if ($open !== null && $open['type'] === 'brewing' && $pk->slot >= 0 && $pk->slot < \pocketmine\core\resource\BrewingStore::SIZE) {
                $this->handleTileContainerSetSlot($addrKey, $session, $pk);
                return;
            }
        } elseif ($pk->windowid === ContainerSetContentPacket::SPECIAL_CREATIVE) {
            // Creative pick: the client selected an item from the creative
            // window. Put a fresh stack of it into the target inventory slot.
            $id = (int)($pk->item[0] ?? 0);
            if ($id > 0 && $pk->slot >= 0 && $pk->slot < InventoryComponent::ARMOR_OFFSET) {
                // Fresh stack from the creative menu: no NBT. The wire slot's
                // NBT field is raw binary, while ItemStack::nbt is an array -
                // creative items never carry one.
                $stack = new ItemStack($id, max(1, (int)($pk->item[1] ?? 1)), (int)($pk->item[2] ?? 0));
                $inventory->set($pk->slot, $stack);
                $this->sendInventorySlot($session['playerRef'], $pk->slot);
            }
            return;
        }
        if ($invSlot < 0) {
            return;
        }
        $id = (int)($pk->item[0] ?? 0);
        $count = (int)($pk->item[1] ?? 0);
        $meta = (int)($pk->item[2] ?? 0);

        // Window-0 ContainerSetSlot packets describe a *move*: the client
        // reports the resulting contents of the affected slots one packet at
        // a time (empty the source slot, then fill the destination). To keep
        // the server authoritative we track per-session move credit: items
        // released by emptying a slot can be re-placed elsewhere, but a claim
        // can never exceed what was actually in the inventory. This makes
        // moves work while a hostile client cannot conjure items it never had.
        /** @var array<string, int> $credit */
        $credit = $session['moveCredit'];
        $creditKey = $id . ':' . $meta;
        $credit[$creditKey] = $credit[$creditKey] ?? 0;

        $current = $inventory->get($invSlot);
        $inSlot = ($current !== null && $current->itemId === $id && $current->meta === $meta)
            ? $current->count
            : 0;

        if ($id <= 0 || $count <= 0) {
            // Slot emptied: release whatever it held into move credit so a
            // two-packet move (empty A, fill B) is accepted.
            if ($current !== null) {
                $heldKey = $current->itemId . ':' . $current->meta;
                $credit[$heldKey] = ($credit[$heldKey] ?? 0) + $current->count;
            }
            $inventory->set($invSlot, null);
        } else {
            // Claim a stack. It must be backed by the same item already in the
            // slot plus credit released by earlier slot-empties this session.
            $need = max(0, $count - $inSlot);
            if ($need > $credit[$creditKey]) {
                return; // hostile claim - reject and keep authoritative state
            }
            $credit[$creditKey] -= $need;
            if ($inSlot > $count) {
                // Reducing the stack in place: the surplus joins move credit.
                $credit[$creditKey] += $inSlot - $count;
            }
            // Slot NBT arrives as raw bytes; parsed-NBT wiring is not done for
            // slots yet, so item NBT is dropped here.
            $inventory->set($invSlot, new ItemStack($id, $meta, $count));
        }
        $session['moveCredit'] = $credit;
        $this->sessions[$addrKey] = $session;
        // Mirror the authoritative slot back (legacy PlayerInventory::sendSlot
        // / ArmorInventory::sendSlot - sendInventorySlot picks the window).
        $this->sendInventorySlot($session['playerRef'], $invSlot);

        // 14.13: an armor-window change is visible to every player, so
        // broadcast the equipped gear (MobArmorEquipmentPacket).
        if ($pk->windowid === ContainerSetContentPacket::SPECIAL_ARMOR) {
            $this->sendMobArmorToAll($session['playerRef']->entityId);
        }
    }

    /**
     * 14.15 chests: right-clicking a chest block opened the window; the
     * client now reports slot moves on that window. A chest move uses the
     * same move credit as window 0 (the credit map is session-wide, so
     * emptying a player slot then filling a chest slot - or vice versa - is
     * accepted as one drag). The chest window id is 2 (legacy: the first
     * container window a player opens).
     */
    private const CHEST_WINDOW_ID = 2;

    /**
     * 14.27 containers: dispenser/hopper/brewing-stand windows. Window ids 4-6
     * (legacy: the next container windows after chest=2 and furnace=3).
     */
    private const DISPENSER_WINDOW_ID = 4;
    private const HOPPER_WINDOW_ID = 5;
    private const BREWING_WINDOW_ID = 6;

    private function openChest(string $addrKey, int $x, int $y, int $z): void {
        $session = $this->sessions[$addrKey] ?? null;
        if ($session === null) {
            return;
        }
        // 14.27: a chest next to another chest opens as one 54-slot double
        // window (type 1 = DOUBLE_CHEST). The pair is detected at open time
        // from block adjacency; slots 0-26 are the left half (min X, or min Z
        // for Z-pairs) and 27-53 the right half.
        $pair = $this->chestPair($x, $y, $z, $session['worldId']);
        $session['openContainer'] = $pair !== null
            ? ['x' => $pair[0], 'y' => $y, 'z' => $pair[1], 'type' => 'chest', 'pair' => ['x' => $pair[2], 'z' => $pair[3]]]
            : ['x' => $x, 'y' => $y, 'z' => $z, 'type' => 'chest', 'pair' => null];
        $this->sessions[$addrKey] = $session;

        // Legacy ContainerInventory::onOpen: ContainerOpenPacket (type 0 =
        // chest / 1 = double chest, block coords) then the full contents.
        // Chest contents are per-world: the same coordinates in another world
        // have a different inventory.
        $chestStore = $this->chestStore($session['worldId']);
        $open = new ContainerOpenPacket();
        $open->windowid = self::CHEST_WINDOW_ID;
        $open->x = $x;
        $open->y = $y;
        $open->z = $z;
        $open->entityId = -1;
        if ($pair !== null) {
            $open->type = 1; // InventoryType::DOUBLE_CHEST
            $open->slots = ChestStore::CHEST_SIZE * 2;
            $this->queuePacket($session['playerRef'], $open);
            // Send the merged 54-slot view: left half then right half.
            $this->sendDoubleChestContents($session['playerRef'], $chestStore->get($pair[0], $y, $pair[1]), $chestStore->get($pair[2], $y, $pair[3]));
        } else {
            $open->type = 0; // InventoryType::CHEST
            $open->slots = ChestStore::CHEST_SIZE;
            $this->queuePacket($session['playerRef'], $open);
            $this->sendChestContents($session['playerRef'], $chestStore->get($x, $y, $z));
        }
    }

    /**
     * The canonical left/right halves of a chest pair at (x,y,z), or null
     * when no adjacent chest exists. Left = smaller X (or smaller Z for
     * same-X Z-pairs) so both players see the same 54-slot layout regardless
     * of which half they clicked.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}|null [leftX, leftZ, rightX, rightZ]
     */
    private function chestPair(int $x, int $y, int $z, int $worldId): ?array {
        foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$dx, $dz]) {
            if (!$this->isChestBlock($x + $dx, $y, $z + $dz, $worldId)) {
                continue;
            }
            if ($dx !== 0) { // X-pair: same Z, X differs
                return [min($x, $x + $dx), $z, max($x, $x + $dx), $z];
            }
            return [$x, min($z, $z + $dz), $x, max($z, $z + $dz)];
        }
        return null;
    }

    /** The merged 54-slot view of a double chest (left 0-26, right 27-53). */
    private function sendDoubleChestContents(PlayerRef $player, \pocketmine\core\component\InventoryComponent $left, \pocketmine\core\component\InventoryComponent $right): void {
        $pk = new ContainerSetContentPacket();
        $pk->windowid = self::CHEST_WINDOW_ID;
        $pk->slots = [];
        foreach ([$left, $right] as $half) {
            for ($i = 0; $i < ChestStore::CHEST_SIZE; $i++) {
                $item = $half->get($i);
                $pk->slots[] = $item !== null ? [$item->itemId, $item->count, $item->meta, $item->nbt] : [0, 0, 0, null];
            }
        }
        $this->queuePacket($player, $pk);
    }

    /**
     * 14.27: open a dispenser (9 slots) / hopper (5 slots) / brewing stand
     * (4 slots) window. All three are plain N-slot inventories routed through
     * the same set-slot + move-credit flow as chests.
     */
    private function openTileContainer(string $addrKey, int $x, int $y, int $z, string $type): void {
        $session = $this->sessions[$addrKey] ?? null;
        if ($session === null) {
            return;
        }
        $session['openContainer'] = ['x' => $x, 'y' => $y, 'z' => $z, 'type' => $type, 'pair' => null];
        $this->sessions[$addrKey] = $session;

        [$windowId, $typeId, $size] = match ($type) {
            'dispenser' => [self::DISPENSER_WINDOW_ID, 10, \pocketmine\core\resource\ContainerStore::DISPENSER_SIZE],
            'hopper' => [self::HOPPER_WINDOW_ID, 12, \pocketmine\core\resource\ContainerStore::HOPPER_SIZE],
            default => [self::BREWING_WINDOW_ID, 7, \pocketmine\core\resource\BrewingStore::SIZE],
        };
        $inv = $this->tileContainerInventory($type, $x, $y, $z, $session['worldId']);
        if ($inv === null) {
            return;
        }
        $open = new ContainerOpenPacket();
        $open->windowid = $windowId;
        $open->type = $typeId;
        $open->slots = $size;
        $open->x = $x;
        $open->y = $y;
        $open->z = $z;
        $open->entityId = -1;
        $this->queuePacket($session['playerRef'], $open);
        $this->sendTileContainerContents($session['playerRef'], $inv, $windowId, $size);
    }

    private function openDispenser(string $addrKey, int $x, int $y, int $z): void {
        $this->openTileContainer($addrKey, $x, $y, $z, 'dispenser');
    }

    private function openHopper(string $addrKey, int $x, int $y, int $z): void {
        $this->openTileContainer($addrKey, $x, $y, $z, 'hopper');
    }

    private function openBrewing(string $addrKey, int $x, int $y, int $z): void {
        $this->openTileContainer($addrKey, $x, $y, $z, 'brewing');
    }

    /**
     * The inventory for a tile container at a position. Brewing stands keep
     * their inventory inside the BrewingStore state struct; dispenser/hopper
     * use the ContainerStore directly.
     */
    private function tileContainerInventory(string $type, int $x, int $y, int $z, int $worldId = 0): ?\pocketmine\core\component\InventoryComponent {
        if ($type === 'brewing') {
            return $this->brewingStore($worldId)->get($x, $y, $z)['inventory'];
        }
        $containerType = $type === 'hopper' ? \pocketmine\core\resource\ContainerStore::TYPE_HOPPER : \pocketmine\core\resource\ContainerStore::TYPE_DISPENSER;
        return $this->containerStore($worldId)->get($containerType, $x, $y, $z);
    }

    private function containerStore(int $worldId = 0): \pocketmine\core\resource\ContainerStore {
        if ($worldId !== 0) {
            $registry = $this->resourceRegistry->get(WorldRegistry::class);
            $store = $registry instanceof WorldRegistry ? $registry->getContainerStore($worldId) : null;
            return $store instanceof \pocketmine\core\resource\ContainerStore ? $store : new \pocketmine\core\resource\ContainerStore();
        }
        $store = $this->resourceRegistry->get(\pocketmine\core\resource\ContainerStore::class);
        return $store instanceof \pocketmine\core\resource\ContainerStore ? $store : new \pocketmine\core\resource\ContainerStore();
    }

    private function brewingStore(int $worldId = 0): \pocketmine\core\resource\BrewingStore {
        if ($worldId !== 0) {
            $registry = $this->resourceRegistry->get(WorldRegistry::class);
            $store = $registry instanceof WorldRegistry ? $registry->getBrewingStore($worldId) : null;
            return $store instanceof \pocketmine\core\resource\BrewingStore ? $store : new \pocketmine\core\resource\BrewingStore();
        }
        $store = $this->resourceRegistry->get(\pocketmine\core\resource\BrewingStore::class);
        return $store instanceof \pocketmine\core\resource\BrewingStore ? $store : new \pocketmine\core\resource\BrewingStore();
    }

    /** Send all slots of a tile container as its window (legacy sendContents). */
    private function sendTileContainerContents(PlayerRef $player, \pocketmine\core\component\InventoryComponent $inv, int $windowId, int $size): void {
        $pk = new ContainerSetContentPacket();
        $pk->windowid = $windowId;
        $pk->slots = [];
        for ($i = 0; $i < $size; $i++) {
            $item = $inv->get($i);
            $pk->slots[] = $item !== null ? [$item->itemId, $item->count, $item->meta, $item->nbt] : [0, 0, 0, null];
        }
        $this->queuePacket($player, $pk);
    }

    private function chestStore(int $worldId = 0): ChestStore {
        // Non-default worlds resolve their own store from the registry; the
        // default world falls back to the global resource instance.
        if ($worldId !== 0) {
            $registry = $this->resourceRegistry->get(WorldRegistry::class);
            $store = $registry instanceof WorldRegistry ? $registry->getChestStore($worldId) : null;
            return $store instanceof ChestStore ? $store : new ChestStore();
        }
        $store = $this->resourceRegistry->get(ChestStore::class);
        return $store instanceof ChestStore ? $store : new ChestStore();
    }

    private function tileEntityStore(int $worldId = 0): ?TileEntityStore {
        // Non-default worlds resolve their own store from the registry; the
        // default world falls back to the global resource instance.
        if ($worldId !== 0) {
            $registry = $this->resourceRegistry->get(WorldRegistry::class);
            $store = $registry instanceof WorldRegistry ? $registry->getTileEntityStore($worldId) : null;
            return $store instanceof TileEntityStore ? $store : null;
        }
        $store = $this->resourceRegistry->get(TileEntityStore::class);
        return $store instanceof TileEntityStore ? $store : null;
    }

    private function isReplaceableBlock(int $blockId): bool {
        $registry = $this->resourceRegistry->get(\pocketmine\core\resource\BlockRegistry::class);
        return $registry instanceof \pocketmine\core\resource\BlockRegistry ? $registry->isReplaceable($blockId) : $blockId === 0;
    }

    /**
     * Send the tile-entity NBT for a position to every viewer in the world
     * (legacy Spawnable::spawnToAll). The payload is little-endian NBT: for a
     * sign, id/x/y/z + Text1-4; for a frame, id/x/y/z + Item/ItemRotation.
     */
    private function broadcastTileEntity(int $x, int $y, int $z, int $worldId = 0): void {
        $payload = $this->tileEntityPayload($x, $y, $z, $worldId);
        if ($payload === null) {
            return;
        }
        $pk = new BlockEntityDataPacket();
        $pk->x = $x;
        $pk->y = $y;
        $pk->z = $z;
        $pk->namedtag = $payload;
        foreach ($this->sessions as $s) {
            if ($s['worldId'] === $worldId) {
                $this->queuePacket($s['playerRef'], clone $pk);
            }
        }
    }

    /**
     * The little-endian NBT payload for a tile entity at a position, or null
     * when the position holds no sign/frame. Mirrors legacy getSpawnCompound.
     */
    private function tileEntityPayload(int $x, int $y, int $z, int $worldId = 0): ?string {
        $tiles = $this->tileEntityStore($worldId);
        if ($tiles === null) {
            return null;
        }
        $sign = $tiles->getSign($x, $y, $z);
        if ($sign !== null) {
            $tags = [
                new StringTag('id', TileEntityStore::TILE_SIGN),
                new IntTag('x', $x),
                new IntTag('y', $y),
                new IntTag('z', $z),
            ];
            foreach (['Text1', 'Text2', 'Text3', 'Text4'] as $i => $line) {
                $tags[] = new StringTag($line, (string)($sign['text'][$i] ?? ''));
            }
            $nbt = new NBT(NBT::LITTLE_ENDIAN);
            $nbt->setData(new CompoundTag('', $tags));
            return $nbt->write();
        }
        $frame = $tiles->getFrame($x, $y, $z);
        if ($frame !== null) {
            $tags = [
                new StringTag('id', TileEntityStore::TILE_ITEM_FRAME),
                new IntTag('x', $x),
                new IntTag('y', $y),
                new IntTag('z', $z),
                new ByteTag('ItemRotation', $frame['rotation']),
                new FloatTag('ItemDropChance', 1.0),
            ];
            $item = $frame['item'];
            if ($item !== null) {
                $tags[] = new CompoundTag('Item', [
                    new ShortTag('id', $item['id']),
                    new ByteTag('Count', $item['count']),
                    new ShortTag('Damage', $item['meta']),
                ]);
            }
            $nbt = new NBT(NBT::LITTLE_ENDIAN);
            $nbt->setData(new CompoundTag('', $tags));
            return $nbt->write();
        }
        return null;
    }

    /** Send the full 27 chest slots as window 2 (legacy sendContents). */
    private function sendChestContents(PlayerRef $player, \pocketmine\core\component\InventoryComponent $inv): void {
        $pk = new ContainerSetContentPacket();
        $pk->windowid = self::CHEST_WINDOW_ID;
        $pk->slots = [];
        for ($i = 0; $i < ChestStore::CHEST_SIZE; $i++) {
            $item = $inv->get($i);
            $pk->slots[] = $item !== null ? [$item->itemId, $item->count, $item->meta, $item->nbt] : [0, 0, 0, null];
        }
        $this->queuePacket($player, $pk);
    }

    /**
     * Send one chest slot to every player with that chest window open (same
     * world). For a double chest the slot is translated to the window layout
     * (left 0-26 / right 27-53): a change in the right half is broadcast as
     * slot + 27, and a viewer who opened the other half sees the same layout
     * because the pair was canonicalized at open time.
     */
    private function broadcastChestSlot(int $x, int $y, int $z, int $slot, \pocketmine\core\component\InventoryComponent $inv, int $worldId = 0): void {
        $pair = $this->chestPair($x, $y, $z, $worldId);
        $windowSlot = $slot;
        if ($pair !== null) {
            // The changed half is the right half when it is not the canonical
            // left position.
            $isRight = !($x === $pair[0] && $z === $pair[1]);
            $windowSlot = $isRight ? $slot + ChestStore::CHEST_SIZE : $slot;
        }
        $item = $inv->get($slot);
        $pk = new ContainerSetSlotPacket();
        $pk->windowid = self::CHEST_WINDOW_ID;
        $pk->slot = $windowSlot;
        $pk->hotbarSlot = $windowSlot;
        $pk->item = $item !== null ? [$item->itemId, $item->count, $item->meta, $item->nbt] : [0, 0, 0, null];
        foreach ($this->sessions as $s) {
            $open = $s['openContainer'];
            if ($open === null || $s['worldId'] !== $worldId || $open['type'] !== 'chest') {
                continue;
            }
            // Match the window: the session opened either half of this pair,
            // or this exact single chest.
            $matches = $open['x'] === $x && $open['y'] === $y && $open['z'] === $z;
            if ($pair !== null) {
                $matches = ($open['x'] === $pair[0] && $open['z'] === $pair[1])
                    || ($open['x'] === $pair[2] && $open['z'] === $pair[3]);
            }
            if ($matches) {
                $this->queuePacket($s['playerRef'], clone $pk);
            }
        }
    }

    /**
     * A chest-window slot change. The client reports the NEW contents of one
     * chest slot per packet; the server applies the authoritative state with
     * the same session-wide move credit as window 0 (an empty-player-slot
     * then fill-chest-slot drag is one move). Every viewer with the same
     * chest open gets the changed slot so the GUI stays in lockstep.
     */
    private function handleChestSetSlot(string $addrKey, array $session, ContainerSetSlotPacket $pk): void {
        $open = $session['openContainer'];
        if ($open === null) {
            return;
        }
        // Route a double-chest slot to the right physical half: 0-26 = left,
        // 27-53 = right (the pair was canonicalized at open time).
        $targetX = $open['x'];
        $targetZ = $open['z'];
        $slot = $pk->slot;
        if ($open['pair'] !== null && $slot >= ChestStore::CHEST_SIZE) {
            $targetX = $open['pair']['x'];
            $targetZ = $open['pair']['z'];
            $slot -= ChestStore::CHEST_SIZE;
        }
        $chest = $this->chestStore($session['worldId'])->get($targetX, $open['y'], $targetZ);
        $id = (int)($pk->item[0] ?? 0);
        $count = (int)($pk->item[1] ?? 0);
        $meta = (int)($pk->item[2] ?? 0);

        /** @var array<string, int> $credit */
        $credit = $session['moveCredit'];
        $creditKey = $id . ':' . $meta;
        $credit[$creditKey] = $credit[$creditKey] ?? 0;

        $current = $chest->get($slot);
        $inSlot = ($current !== null && $current->itemId === $id && $current->meta === $meta)
            ? $current->count
            : 0;

        if ($id <= 0 || $count <= 0) {
            // Slot emptied: release what it held into move credit (the client
            // will report the destination slot next packet of a drag).
            if ($current !== null) {
                $heldKey = $current->itemId . ':' . $current->meta;
                $credit[$heldKey] = ($credit[$heldKey] ?? 0) + $current->count;
            }
            $chest->set($slot, null);
        } else {
            // Claim a stack backed by the same item already in the slot plus
            // released credit (never conjured from nothing).
            $need = max(0, $count - $inSlot);
            if ($need > $credit[$creditKey]) {
                return; // hostile claim - keep the authoritative state
            }
            $credit[$creditKey] -= $need;
            if ($inSlot > $count) {
                $credit[$creditKey] += $inSlot - $count;
            }
            $chest->set($slot, new ItemStack($id, $meta, $count));
        }
        $session['moveCredit'] = $credit;
        $this->sessions[$addrKey] = $session;
        $this->broadcastChestSlot($targetX, $open['y'], $targetZ, $slot, $chest, $session['worldId']);
    }

    /**
     * 14.27: a dispenser/hopper/brewing-stand slot change. Same authoritative
     * apply + session-wide move credit as the chest path; every viewer with
     * the same container open gets the changed slot.
     */
    private function handleTileContainerSetSlot(string $addrKey, array $session, ContainerSetSlotPacket $pk): void {
        $open = $session['openContainer'];
        if ($open === null) {
            return;
        }
        $inv = $this->tileContainerInventory($open['type'], $open['x'], $open['y'], $open['z'], $session['worldId']);
        if ($inv === null) {
            return;
        }
        $slot = $pk->slot;
        $id = (int)($pk->item[0] ?? 0);
        $count = (int)($pk->item[1] ?? 0);
        $meta = (int)($pk->item[2] ?? 0);

        /** @var array<string, int> $credit */
        $credit = $session['moveCredit'];
        $creditKey = $id . ':' . $meta;
        $credit[$creditKey] = $credit[$creditKey] ?? 0;

        $current = $inv->get($slot);
        $inSlot = ($current !== null && $current->itemId === $id && $current->meta === $meta)
            ? $current->count
            : 0;

        if ($id <= 0 || $count <= 0) {
            if ($current !== null) {
                $heldKey = $current->itemId . ':' . $current->meta;
                $credit[$heldKey] = ($credit[$heldKey] ?? 0) + $current->count;
            }
            $inv->set($slot, null);
        } else {
            $need = max(0, $count - $inSlot);
            if ($need > $credit[$creditKey]) {
                return; // hostile claim - keep the authoritative state
            }
            $credit[$creditKey] -= $need;
            if ($inSlot > $count) {
                $credit[$creditKey] += $inSlot - $count;
            }
            $inv->set($slot, new ItemStack($id, $meta, $count));
        }
        $session['moveCredit'] = $credit;
        $this->sessions[$addrKey] = $session;
        // Persist brewing stands (their inventory lives in the state struct).
        if ($open['type'] === 'brewing') {
            $this->brewingStore($session['worldId'])->put($open['x'], $open['y'], $open['z'], $this->brewingStore($session['worldId'])->get($open['x'], $open['y'], $open['z']));
        }
        $this->broadcastTileContainerSlot($open['type'], $open['x'], $open['y'], $open['z'], $slot, $inv, $session['worldId']);
    }

    /** Send one slot of a tile container to every viewer with it open. */
    private function broadcastTileContainerSlot(string $type, int $x, int $y, int $z, int $slot, \pocketmine\core\component\InventoryComponent $inv, int $worldId = 0): void {
        $windowId = match ($type) {
            'dispenser' => self::DISPENSER_WINDOW_ID,
            'hopper' => self::HOPPER_WINDOW_ID,
            default => self::BREWING_WINDOW_ID,
        };
        $item = $inv->get($slot);
        $pk = new ContainerSetSlotPacket();
        $pk->windowid = $windowId;
        $pk->slot = $slot;
        $pk->hotbarSlot = $slot;
        $pk->item = $item !== null ? [$item->itemId, $item->count, $item->meta, $item->nbt] : [0, 0, 0, null];
        foreach ($this->sessions as $s) {
            $open = $s['openContainer'];
            if ($open !== null && $s['worldId'] === $worldId && $open['type'] === $type
                && $open['x'] === $x && $open['y'] === $y && $open['z'] === $z) {
                $this->queuePacket($s['playerRef'], clone $pk);
            }
        }
    }

    /**
     * 14.27 public hook for HopperSystem/BrewingSystem: a tile container's
     * contents changed on the world tick. Resync the changed slot (or all
     * slots) to every viewer with that container open in the world.
     */
    public function syncContainer(string $type, int $x, int $y, int $z, ?int $slot, int $worldId = 0): void {
        $inv = $this->tileContainerInventory($type, $x, $y, $z, $worldId);
        if ($inv === null) {
            return;
        }
        if ($slot !== null) {
            $this->broadcastTileContainerSlot($type, $x, $y, $z, $slot, $inv, $worldId);
            return;
        }
        // Full resync: refresh every viewer's window contents.
        $windowId = match ($type) {
            'dispenser' => self::DISPENSER_WINDOW_ID,
            'hopper' => self::HOPPER_WINDOW_ID,
            default => self::BREWING_WINDOW_ID,
        };
        $size = match ($type) {
            'dispenser' => \pocketmine\core\resource\ContainerStore::DISPENSER_SIZE,
            'hopper' => \pocketmine\core\resource\ContainerStore::HOPPER_SIZE,
            default => \pocketmine\core\resource\BrewingStore::SIZE,
        };
        foreach ($this->sessions as $s) {
            $open = $s['openContainer'];
            if ($open !== null && $s['worldId'] === $worldId && $open['type'] === $type
                && $open['x'] === $x && $open['y'] === $y && $open['z'] === $z) {
                $this->sendTileContainerContents($s['playerRef'], $inv, $windowId, $size);
            }
        }
    }

    /**
     * 14.16 furnaces: right-clicking a furnace opened the window. Furnace
     * slots are 0 (smelting), 1 (fuel), 2 (result); the client drives moves
     * through the same session-wide move credit as chests/window 0. Window
     * id 3 (legacy: the second container window a player opens).
     */
    private const FURNACE_WINDOW_ID = 3;

    private function openFurnace(string $addrKey, int $x, int $y, int $z): void {
        $session = $this->sessions[$addrKey] ?? null;
        if ($session === null) {
            return;
        }
        $session['openContainer'] = ['x' => $x, 'y' => $y, 'z' => $z, 'type' => 'furnace'];
        $this->sessions[$addrKey] = $session;

        // Legacy FurnaceInventory::onOpen: ContainerOpenPacket (type 3 =
        // InventoryType::FURNACE, 3 slots, block coords) then full contents.
        // Furnace state is per-world like chests.
        $furnaceStore = $this->furnaceStore($session['worldId']);
        $state = $furnaceStore->get($x, $y, $z);
        $open = new ContainerOpenPacket();
        $open->windowid = self::FURNACE_WINDOW_ID;
        $open->type = 3; // InventoryType::FURNACE
        $open->slots = \pocketmine\core\resource\FurnaceStore::SIZE;
        $open->x = $x;
        $open->y = $y;
        $open->z = $z;
        $open->entityId = -1;
        $this->queuePacket($session['playerRef'], $open);
        $this->sendFurnaceContents($session['playerRef'], $state['inventory']);
    }

    private function furnaceStore(int $worldId = 0): \pocketmine\core\resource\FurnaceStore {
        if ($worldId !== 0) {
            $registry = $this->resourceRegistry->get(WorldRegistry::class);
            $store = $registry instanceof WorldRegistry ? $registry->getFurnaceStore($worldId) : null;
            return $store instanceof \pocketmine\core\resource\FurnaceStore ? $store : new \pocketmine\core\resource\FurnaceStore();
        }
        $store = $this->resourceRegistry->get(\pocketmine\core\resource\FurnaceStore::class);
        return $store instanceof \pocketmine\core\resource\FurnaceStore ? $store : new \pocketmine\core\resource\FurnaceStore();
    }

    /** Send the full 3 furnace slots as window 3 (legacy sendContents). */
    private function sendFurnaceContents(PlayerRef $player, \pocketmine\core\component\InventoryComponent $inv): void {
        $pk = new ContainerSetContentPacket();
        $pk->windowid = self::FURNACE_WINDOW_ID;
        $pk->slots = [];
        for ($i = 0; $i < \pocketmine\core\resource\FurnaceStore::SIZE; $i++) {
            $item = $inv->get($i);
            $pk->slots[] = $item !== null ? [$item->itemId, $item->count, $item->meta, $item->nbt] : [0, 0, 0, null];
        }
        $this->queuePacket($player, $pk);
    }

    /** Send one furnace slot to every player with that furnace open (same world). */
    private function broadcastFurnaceSlot(int $x, int $y, int $z, int $slot, \pocketmine\core\component\InventoryComponent $inv, int $worldId = 0): void {
        $item = $inv->get($slot);
        $pk = new ContainerSetSlotPacket();
        $pk->windowid = self::FURNACE_WINDOW_ID;
        $pk->slot = $slot;
        $pk->hotbarSlot = $slot;
        $pk->item = $item !== null ? [$item->itemId, $item->count, $item->meta, $item->nbt] : [0, 0, 0, null];
        foreach ($this->sessions as $s) {
            $open = $s['openContainer'];
            if ($open !== null && $s['worldId'] === $worldId && $open['type'] === 'furnace'
                && $open['x'] === $x && $open['y'] === $y && $open['z'] === $z) {
                $this->queuePacket($s['playerRef'], clone $pk);
            }
        }
    }

    /**
     * A furnace-window slot change. Same authoritative apply + session-wide
     * move credit as the chest path; every viewer with the same furnace open
     * gets the changed slot.
     */
    private function handleFurnaceSetSlot(string $addrKey, array $session, ContainerSetSlotPacket $pk): void {
        $open = $session['openContainer'];
        if ($open === null) {
            return;
        }
        $furnace = $this->furnaceStore($session['worldId'])->get($open['x'], $open['y'], $open['z']);
        $inv = $furnace['inventory'];
        $slot = $pk->slot;
        $id = (int)($pk->item[0] ?? 0);
        $count = (int)($pk->item[1] ?? 0);
        $meta = (int)($pk->item[2] ?? 0);

        /** @var array<string, int> $credit */
        $credit = $session['moveCredit'];
        $creditKey = $id . ':' . $meta;
        $credit[$creditKey] = $credit[$creditKey] ?? 0;

        $current = $inv->get($slot);
        $inSlot = ($current !== null && $current->itemId === $id && $current->meta === $meta)
            ? $current->count
            : 0;

        if ($id <= 0 || $count <= 0) {
            // Slot emptied: release what it held into move credit.
            if ($current !== null) {
                $heldKey = $current->itemId . ':' . $current->meta;
                $credit[$heldKey] = ($credit[$heldKey] ?? 0) + $current->count;
            }
            $inv->set($slot, null);
        } else {
            $need = max(0, $count - $inSlot);
            if ($need > $credit[$creditKey]) {
                return; // hostile claim - keep the authoritative state
            }
            $credit[$creditKey] -= $need;
            if ($inSlot > $count) {
                $credit[$creditKey] += $inSlot - $count;
            }
            $inv->set($slot, new ItemStack($id, $meta, $count));
        }
        $session['moveCredit'] = $credit;
        $this->sessions[$addrKey] = $session;
        $this->furnaceStore($session['worldId'])->put($open['x'], $open['y'], $open['z'], $furnace);
        $this->broadcastFurnaceSlot($open['x'], $open['y'], $open['z'], $slot, $inv, $session['worldId']);
    }

    /**
     * 14.16 public hook for FurnaceSystem: the furnace's burn/cook state
     * changed on the world tick. Resync the changed slot (or all slots) to
     * every viewer with that furnace open, and - only when the lit/unlit
     * block id actually flipped - re-broadcast the block state (a produce
     * tick must not spam UpdateBlockPacket to every session).
     */
    public function syncFurnace(int $x, int $y, int $z, ?int $slot = null, bool $blockChanged = false, int $worldId = 0): void {
        $furnace = $this->furnaceStore($worldId)->get($x, $y, $z);
        $inv = $furnace['inventory'];
        if ($blockChanged) {
            $this->broadcastBlockState($x, $y, $z, $worldId);
        }
        if ($slot !== null) {
            $this->broadcastFurnaceSlot($x, $y, $z, $slot, $inv, $worldId);
        } else {
            foreach ($this->sessions as $s) {
                $open = $s['openContainer'];
                if ($open !== null && $s['worldId'] === $worldId && $open['type'] === 'furnace'
                    && $open['x'] === $x && $open['y'] === $y && $open['z'] === $z) {
                    $this->sendFurnaceContents($s['playerRef'], $inv);
                }
            }
        }
    }

    /**
     * 14.16: a furnace block was broken - spill its contents (input, fuel,
     * result) as dropped items, forget the store entry, and close the window
     * of every session that had it open.
     */
    private function onFurnaceBroken(int $x, int $y, int $z, array $breaker): void {
        $worldId = $breaker['worldId'] ?? 0;
        $store = $this->furnaceStore($worldId);
        $inv = $store->remove($x, $y, $z);
        foreach ($inv->getContents() as $item) {
            $this->entitySpawnService->spawnItem($x + 0.5, $y + 0.5, $z + 0.5, $item);
        }
        foreach ($this->sessions as $key => $s) {
            $open = $s['openContainer'];
            if ($open !== null && $s['worldId'] === $worldId && $open['type'] === 'furnace'
                && $open['x'] === $x && $open['y'] === $y && $open['z'] === $z) {
                $s['openContainer'] = null;
                $this->sessions[$key] = $s;
                $close = new ContainerClosePacket();
                $close->windowid = self::FURNACE_WINDOW_ID;
                $this->queuePacket($s['playerRef'], $close);
            }
        }
    }

    /**
     * 14.27: a dispenser/hopper/brewing-stand block was broken - spill its
     * contents as item entities, forget the store entry, and close the window
     * of every session that had it open.
     */
    private function onTileContainerBroken(string $type, int $x, int $y, int $z, array $breaker): void {
        $worldId = $breaker['worldId'] ?? 0;
        if ($type === 'brewing') {
            $inv = $this->brewingStore($worldId)->remove($x, $y, $z);
        } else {
            $containerType = $type === 'hopper' ? \pocketmine\core\resource\ContainerStore::TYPE_HOPPER : \pocketmine\core\resource\ContainerStore::TYPE_DISPENSER;
            $inv = $this->containerStore($worldId)->remove($containerType, $x, $y, $z);
        }
        foreach ($inv->getContents() as $item) {
            $this->entitySpawnService->spawnItem($x + 0.5, $y + 0.5, $z + 0.5, $item);
        }
        $windowId = match ($type) {
            'dispenser' => self::DISPENSER_WINDOW_ID,
            'hopper' => self::HOPPER_WINDOW_ID,
            default => self::BREWING_WINDOW_ID,
        };
        foreach ($this->sessions as $key => $s) {
            $open = $s['openContainer'];
            if ($open !== null && $s['worldId'] === $worldId && $open['type'] === $type
                && $open['x'] === $x && $open['y'] === $y && $open['z'] === $z) {
                $s['openContainer'] = null;
                $this->sessions[$key] = $s;
                $close = new ContainerClosePacket();
                $close->windowid = $windowId;
                $this->queuePacket($s['playerRef'], $close);
            }
        }
    }

    /**
     * 14.27 dispenser dispense hook: ejects a random item from a random
     * occupied slot in the direction the dispenser faces (block meta 0-5 =
     * down, up, north, south, west, east). Called by the future redstone
     * engine when the dispenser is powered; exposed as a public hook so tests
     * and other systems can trigger it directly.
     */
    public function dispenseDispenser(int $x, int $y, int $z, int $worldId = 0): bool {
        $store = $this->containerStore($worldId);
        $inv = $store->get(\pocketmine\core\resource\ContainerStore::TYPE_DISPENSER, $x, $y, $z);
        $occupied = [];
        foreach ($inv->getContents() as $slot => $item) {
            if ($item->count > 0) {
                $occupied[] = $slot;
            }
        }
        if ($occupied === []) {
            return false; // empty dispenser: nothing to eject
        }
        $slot = $occupied[array_rand($occupied)];
        $stack = $inv->get($slot);
        if ($stack === null) {
            return false;
        }
        $one = new ItemStack($stack->itemId, $stack->meta, 1, $stack->nbt);
        $inv->remove($slot, 1);
        $this->syncContainer('dispenser', $x, $y, $z, $slot, $worldId);

        // Facing from block meta (legacy Dispenser block damage): 0 down,
        // 1 up, 2 north, 3 south, 4 west, 5 east.
        $chunks = $this->getChunkStore($worldId);
        $meta = $chunks !== null ? $chunks->getBlockMeta($x, $y, $z) : 0;
        [$dx, $dy, $dz] = match ($meta % 6) {
            0 => [0, -1, 0],
            1 => [0, 1, 0],
            2 => [0, 0, -1],
            3 => [0, 0, 1],
            4 => [-1, 0, 0],
            default => [1, 0, 0],
        };
        // Spawn the item just outside the dispenser face, pushed along the
        // facing direction (a real entity so viewers see it fly out).
        $ref = $this->entitySpawnService->spawnItem(
            $x + 0.5 + $dx * 0.6,
            $y + 0.5 + $dy * 0.6,
            $z + 0.5 + $dz * 0.6,
            $one,
            $worldId,
        );
        $entity = $ref->getEntity();
        if ($entity !== null) {
            $vel = $entity->get(\pocketmine\core\component\VelocityComponent::class);
            if ($vel !== null) {
                $vel->x = $dx * 0.5;
                $vel->y = $dy === -1 ? -0.3 : 0.2;
                $vel->z = $dz * 0.5;
            }
        }
        return true;
    }

    /**
     * The client closed the chest window (or the server told it to): forget
     * the open container and mirror the close back (legacy
     * ContainerInventory::onClose).
     */
    private function handleContainerClose(string $addrKey, ContainerClosePacket $pk): void {
        $session = $this->sessions[$addrKey] ?? null;
        if ($session === null || $session['openContainer'] === null) {
            return;
        }
        $session['openContainer'] = null;
        $this->sessions[$addrKey] = $session;
        // Legacy mirrors the close to the client that sent it.
        $close = new ContainerClosePacket();
        $close->windowid = $pk->windowid;
        $this->queuePacket($session['playerRef'], $close);
    }

    /**
     * Drop item (14.7): the player pressed Q with an item selected. Drop one
     * item from the held (or matching) stack, spawn a real item entity in
     * front of the player that viewers see (AddItemEntityPacket via the
     * per-tick entity broadcast), and refresh the actor's window.
     */
    private function handleDropItem(string $addrKey, DropItemPacket $pk): void {
        $session = $this->sessions[$addrKey] ?? null;
        if ($session === null) {
            return;
        }
        $entity = $session['entityRef']->getEntity();
        $inventory = $entity?->get(InventoryComponent::class);
        if ($inventory === null) {
            return;
        }
        // The client says which item it is dropping (id/count/damage). Only
        // drop a single item per Q press regardless of the claimed count, so
        // a hostile frame cannot empty a whole stack in one packet.
        $id = (int)($pk->item[0] ?? 0);
        if ($id <= 0) {
            return; // air - nothing to drop
        }
        $meta = (int)($pk->item[2] ?? 0);

        // Find a matching stack: the held slot first, then a scan (the client
        // may drop from an inventory slot with an empty hand).
        $slot = -1;
        $held = $inventory->get($inventory->heldSlot);
        if ($held !== null && $held->itemId === $id && $held->meta === $meta) {
            $slot = $inventory->heldSlot;
        } else {
            foreach ($inventory->getContents() as $s => $item) {
                if ($item->itemId === $id && $item->meta === $meta) {
                    $slot = $s;
                    break;
                }
            }
        }
        if ($slot < 0) {
            return; // the player does not hold any matching item
        }
        $removed = $inventory->remove($slot, 1);
        if ($removed === null) {
            return;
        }

        // Spawn the dropped item entity in front of the player (eye height),
        // thrown slightly toward the look direction. The per-tick entity
        // broadcast will AddItemEntity it to every viewer.
        $pos = $session['entityRef']->getPosition();
        $rot = $session['entityRef']->getRotation();
        if ($pos !== null) {
            [$dx, $dy, $dz] = $rot !== null ? $rot->getForwardVector() : [0.0, 0.0, 1.0];
            $this->entitySpawnService->spawnItem(
                $pos->x + $dx * 0.6,
                $pos->y + 1.2,
                $pos->z + $dz * 0.6,
                new ItemStack($removed->itemId, $removed->meta, 1, $removed->nbt),
            );
        }
        // Reflect the consumed stack in the actor's own window.
        $this->sendInventorySlot($session['playerRef'], $slot);
    }

    /**
     * 14.3 combat: ACTION_LEFT_CLICK attacks the target entity through the
     * full combat pipeline (damage event, armor, knockback, death + loot).
     * On a landed hit every session that can see the target gets the
     * hurt/death animation; the health drop itself is synced by the per-tick
     * entity state pass (SetEntityDataPacket to viewers, SetHealthPacket to
     * the victim's own HUD). ACTION_RIGHT_CLICK routes through the
     * interaction service (item pickup, breeding hooks...).
     */
    private function handleInteract(string $addrKey, InteractPacket $pk): void {
        $session = $this->sessions[$addrKey] ?? null;
        if ($session === null) {
            return;
        }
        $selfId = $session['playerRef']->entityId;
        if ($pk->target === $selfId || $pk->target <= 0) {
            return; // attacking yourself (or a bogus id) is a no-op
        }
        // Dead players (corpse waiting for respawn) cannot attack.
        $attackerHealth = $session['entityRef']->getEntity()?->get(HealthComponent::class);
        if ($attackerHealth === null || $attackerHealth->current <= 0) {
            return;
        }
        $targetRef = EntityRef::create($pk->target, $this->world);

        if ($pk->action === InteractPacket::ACTION_LEFT_CLICK) {
            $target = $targetRef->getEntity();
            $healthBefore = $target?->get(HealthComponent::class)?->current;
            if ($healthBefore === null || $healthBefore <= 0) {
                return; // corpse or non-living target: nothing to hit
            }
            if (!$this->entityInteractionService->attack($session['entityRef'], $targetRef)) {
                return; // cancelled by a plugin damage event
            }
            $target = $targetRef->getEntity();
            $healthAfter = $target?->get(HealthComponent::class)?->current;
            $event = new EntityEventPacket();
            $event->eid = $pk->target;
            $event->event = $healthAfter !== null && $healthAfter <= 0
                ? EntityEventPacket::DEATH_ANIMATION
                : EntityEventPacket::HURT_ANIMATION;
            // Only sessions that actually see the target (its Add packet was
            // already sent) get the animation - never for an unknown entity.
            foreach ($this->sessions as $s) {
                if (isset($s['knownEntities'][$pk->target])) {
                    $this->queuePacket($s['playerRef'], clone $event);
                }
            }
            return;
        }

        if ($pk->action === InteractPacket::ACTION_RIGHT_CLICK) {
            // 14.25: right-clicking a vehicle mounts it (link rider to
            // vehicle), unless it already has a rider.
            $targetEntity = $targetRef->getEntity();
            $targetMeta = $targetEntity?->get(MetadataComponent::class);
            if ($targetEntity?->has(\pocketmine\core\constants\EntityTags::VEHICLE) ?? false) {
                $riderId = (int)($targetMeta?->get(\pocketmine\core\constants\MetadataKeys::VEHICLE_RIDER_ID) ?? 0);
                if ($riderId <= 0) {
                    $this->mountVehicle($addrKey, $pk->target, $targetEntity);
                }
                return;
            }
            if ($this->entityInteractionService->interact($session['entityRef'], $targetRef)) {
                // 14.5: the picked-up stack must appear in the actor's own
                // inventory window (the walk-over path syncs through the
                // public syncInventoryContents hook instead).
                $this->syncInventoryContents($selfId);
            }
            return;
        }

        // 14.25: ACTION_LEAVE_VEHICLE dismounts the player from whatever
        // vehicle they are riding (legacy Player::handleInteract).
        if ($pk->action === InteractPacket::ACTION_LEAVE_VEHICLE) {
            $this->dismountPlayer($addrKey);
        }
    }

    /**
     * 14.25: mount the player onto a vehicle entity. The link is stored both
     * ways (vehicle -> rider id, rider -> vehicle id) so VehicleSystem can
     * move the rider and the per-tick pass can render the link. Every viewer
     * who sees the vehicle gets the SetEntityLinkPacket (type RIDE).
     */
    private function mountVehicle(string $addrKey, int $vehicleId, Entity $vehicle): void {
        $session = $this->sessions[$addrKey] ?? null;
        if ($session === null) {
            return;
        }
        $riderId = $session['playerRef']->entityId;
        $vehicleMeta = $vehicle->get(MetadataComponent::class);
        $rider = $session['entityRef']->getEntity();
        $riderMeta = $rider?->get(MetadataComponent::class);
        if ($vehicleMeta === null || $riderMeta === null) {
            return;
        }
        $vehicleMeta->set(\pocketmine\core\constants\MetadataKeys::VEHICLE_RIDER_ID, $riderId);
        $riderMeta->set(\pocketmine\core\constants\MetadataKeys::RIDING_VEHICLE_ID, $vehicleId);
        // Snap the rider onto the vehicle immediately.
        $vehiclePos = $vehicle->get(PositionComponent::class);
        $riderPos = $rider?->get(PositionComponent::class);
        if ($vehiclePos !== null && $riderPos !== null) {
            $riderPos->x = $vehiclePos->x;
            $riderPos->y = $vehiclePos->y + 0.5;
            $riderPos->z = $vehiclePos->z;
        }
        $this->broadcastLink($vehicleId, $riderId, SetEntityLinkPacket::TYPE_RIDE);
    }

    /**
     * 14.25: dismount the player from any vehicle. Clears both link halves
     * and broadcasts the link removal to every viewer.
     */
    private function dismountPlayer(string $addrKey): void {
        $session = $this->sessions[$addrKey] ?? null;
        if ($session === null) {
            return;
        }
        $rider = $session['entityRef']->getEntity();
        $riderMeta = $rider?->get(MetadataComponent::class);
        $vehicleId = (int)($riderMeta?->get(\pocketmine\core\constants\MetadataKeys::RIDING_VEHICLE_ID) ?? 0);
        if ($vehicleId <= 0) {
            return;
        }
        $riderMeta?->remove(\pocketmine\core\constants\MetadataKeys::RIDING_VEHICLE_ID);
        $vehicle = $this->world->getEntity($vehicleId);
        $vehicleMeta = $vehicle?->get(MetadataComponent::class);
        $vehicleMeta?->remove(\pocketmine\core\constants\MetadataKeys::VEHICLE_RIDER_ID);
        $this->broadcastLink($vehicleId, $session['playerRef']->entityId, SetEntityLinkPacket::TYPE_REMOVE);
    }

    /** Send a SetEntityLinkPacket to every session that sees the vehicle. */
    private function broadcastLink(int $vehicleId, int $riderId, int $type): void {
        $pk = new SetEntityLinkPacket();
        $pk->from = $vehicleId;
        $pk->to = $riderId;
        $pk->type = $type;
        foreach ($this->sessions as $s) {
            if (isset($s['knownEntities'][$vehicleId]) || $s['playerRef']->entityId === $riderId) {
                $this->queuePacket($s['playerRef'], clone $pk);
            }
        }
    }

    /**
     * 14.25: the client streams vehicle input while riding (motX = forward /
     * back, motY = strafe, plus jump/sneak flags). Forward the input to the
     * vehicle's metadata so VehicleSystem can drive it; sneaking while riding
     * is the vanilla dismount (legacy Player also dismounts on sneak).
     */
    private function handlePlayerInput(string $addrKey, PlayerInputPacket $pk): void {
        $session = $this->sessions[$addrKey] ?? null;
        if ($session === null) {
            return;
        }
        $riderMeta = $session['entityRef']->getEntity()?->get(MetadataComponent::class);
        $vehicleId = (int)($riderMeta?->get(\pocketmine\core\constants\MetadataKeys::RIDING_VEHICLE_ID) ?? 0);
        if ($vehicleId <= 0) {
            return;
        }
        if ($pk->sneaking) {
            $this->dismountPlayer($addrKey);
            return;
        }
        $vehicle = $this->world->getEntity($vehicleId);
        $vehicleMeta = $vehicle?->get(MetadataComponent::class);
        if ($vehicleMeta === null) {
            return;
        }
        // motX = forward/back, motY = strafe (protocol 84 layout). The yaw-
        // relative steering happens in VehicleSystem from these values.
        $vehicleMeta->set(\pocketmine\core\constants\MetadataKeys::VEHICLE_INPUT_Z, $pk->motX);
        $vehicleMeta->set(\pocketmine\core\constants\MetadataKeys::VEHICLE_INPUT_X, $pk->motY);
        $vehicleMeta->set(\pocketmine\core\constants\MetadataKeys::VEHICLE_JUMPING, $pk->jumping);
    }

    /**
     * 14.5: reflect an inventory change to the owning client. Public because
     * the walk-over ItemPickupSystem has no session access - it resolves the
     * session service lazily and calls this after a successful pickup.
     */
    public function syncInventoryContents(int $entityId): void {
        foreach ($this->sessions as $session) {
            if ($session['playerRef']->entityId === $entityId) {
                $this->sendInventoryContents($session['playerRef']);
                return;
            }
        }
    }

    /**
     * 14.10: reflect a single inventory slot change to the owning client.
     * Public because the tool-durability wear path (ItemDurability) runs in
     * services that have no session access - it resolves the session service
     * lazily and calls this after degrading or breaking the held tool.
     */
    public function syncInventorySlot(int $entityId, int $slot): void {
        foreach ($this->sessions as $session) {
            if ($session['playerRef']->entityId === $entityId) {
                $this->sendInventorySlot($session['playerRef'], $slot);
                return;
            }
        }
    }

    /**
     * 14.23 throwing: launch the held throwable (snowball/egg/splash potion)
     * along the player's facing at legacy speed. The projectile rides the
     * ArrowSystem pipeline; potions carry their item meta as POTION_ID so the
     * impact splash knows which effect to apply. Survival consumes one item;
     * creative throws infinitely (legacy ProjectileItem::onActivate).
     */
    private function throwItem(string $addrKey, array $session, ItemStack $held): void {
        $entity = $session['entityRef']->getEntity();
        $pos = $session['entityRef']->getPosition();
        $rot = $session['entityRef']->getRotation();
        if ($entity === null || $pos === null || $rot === null) {
            return;
        }
        $creative = GameMode::coerce($entity->get(MetadataComponent::class)?->get(MetadataKeys::GAMEMODE)) === GameMode::Creative;

        // Legacy throw direction from yaw/pitch; speed = 1.5 blocks/tick.
        $yaw = deg2rad($rot->yaw);
        $pitch = deg2rad($rot->pitch);
        $dx = -sin($yaw) * cos($pitch);
        $dy = -sin($pitch);
        $dz = cos($yaw) * cos($pitch);
        $speed = 1.5 * 20; // blocks/second

        $type = match ($held->itemId) {
            ItemIds::SNOWBALL => EntityType::Snowball,
            ItemIds::EGG => EntityType::Egg,
            default => EntityType::ThrownPotion,
        };
        $projectile = $this->entitySpawnService->spawnProjectile(
            $type,
            $pos->x,
            $pos->y + 1.62, // eye height
            $pos->z,
            $dx * $speed,
            $dy * $speed,
            $dz * $speed,
            $session['entityRef'],
        );
        $projectileEntity = $projectile->getEntity();
        if ($projectileEntity) {
            $meta = $projectileEntity->get(MetadataComponent::class);
            if ($meta && $held->itemId === ItemIds::SPLASH_POTION) {
                $meta->set(MetadataKeys::POTION_ID, $held->meta);
            }
        }

        if (!$creative) {
            $inventory = $entity->get(InventoryComponent::class);
            if ($inventory !== null) {
                $inventory->remove($inventory->heldSlot, 1);
                $this->sendInventorySlot($session['playerRef'], $inventory->heldSlot);
            }
        }
    }

    /**
     * 14.23 drinking: a drinkable potion applies its effect to the player and
     * (in survival) becomes a glass bottle, mirroring legacy Potion::onConsume.
     */
    private function drinkPotion(string $addrKey, array $session, ItemStack $held): void {
        $entity = $session['entityRef']->getEntity();
        if ($entity === null) {
            return;
        }
        $kernel = \pocketmine\Kernel::getInstance();
        $potionService = $kernel?->getPotionService();
        if ($potionService === null) {
            return;
        }
        $registry = $this->resourceRegistry->get(\pocketmine\core\resource\PotionRegistry::class);
        $effect = $registry instanceof \pocketmine\core\resource\PotionRegistry ? $registry->get($held->meta) : null;
        $potionService->apply($session['entityRef'], $effect, false);

        // Animate USE_ITEM to the eater + viewers (legacy onConsume).
        $event = new EntityEventPacket();
        $event->eid = $session['playerRef']->entityId;
        $event->event = EntityEventPacket::USE_ITEM;
        foreach ($this->sessions as $s) {
            $this->queuePacket($s['playerRef'], clone $event);
        }

        $creative = GameMode::coerce($entity->get(MetadataComponent::class)?->get(MetadataKeys::GAMEMODE)) === GameMode::Creative;
        if (!$creative) {
            $inventory = $entity->get(InventoryComponent::class);
            if ($inventory !== null) {
                $inventory->remove($inventory->heldSlot, 1);
                $inventory->add(new ItemStack(ItemIds::GLASS_BOTTLE, 1, 0));
                $this->sendInventorySlot($session['playerRef'], $inventory->heldSlot);
            }
        }
    }

    /**
     * 14.11 eating: consume one food item from the held slot, apply its
     * hunger/saturation restore (capped), animate USE_ITEM to the eater and
     * all viewers (legacy Food::onConsume), then refresh the actor's slot and
     * HUD food bar. Refuses when the player is already full.
     *
     * @param array{0: int, 1: float} $food [hunger restore, saturation restore]
     */
    private function eat(string $addrKey, array $session, ItemStack $held, array $food): void {
        $entity = $session['entityRef']->getEntity();
        $hunger = $entity?->get(HungerComponent::class);
        if ($hunger === null || $hunger->hunger >= Hunger::MAX_FOOD) {
            return; // full (or no hunger state): cannot eat
        }
        $inventory = $entity?->get(InventoryComponent::class);
        if ($inventory === null) {
            return;
        }
        $removed = $inventory->remove($inventory->heldSlot, 1);
        if ($removed === null) {
            return;
        }
        Hunger::applyFood($session['entityRef'], (int)$food[0], (float)$food[1]);

        // Legacy Food::onConsume: USE_ITEM animation to the eater + viewers.
        $event = new EntityEventPacket();
        $event->eid = $session['playerRef']->entityId;
        $event->event = EntityEventPacket::USE_ITEM;
        foreach ($this->sessions as $s) {
            $this->queuePacket($s['playerRef'], clone $event);
        }
        $this->sendInventorySlot($session['playerRef'], $inventory->heldSlot);
        $this->syncFoodFor($session['playerRef']->entityId);
    }

    /**
     * 14.11: push the player's food bars to the client via the legacy
     * attribute packet (player.hunger + player.saturation). Called after
     * eating, by the HungerSystem when the bars drain, and at login so the
     * bar starts full.
     */
    public function syncFoodFor(int $entityId): void {
        foreach ($this->sessions as $session) {
            if ($session['playerRef']->entityId !== $entityId) {
                continue;
            }
            $hunger = $session['entityRef']->getEntity()?->get(HungerComponent::class);
            $pk = new UpdateAttributesPacket();
            $pk->entityId = 0; // legacy: 0 targets the player's own HUD
            $pk->entries = [
                [0.0, 20.0, (float)($hunger?->hunger ?? 20.0), 'player.hunger'],
                [0.0, 20.0, (float)($hunger?->saturation ?? 5.0), 'player.saturation'],
            ];
            $this->queuePacket($session['playerRef'], $pk);
            return;
        }
    }

    /**
     * 14.9: push the player's XP bar (level + progress) to the client via
     * the legacy attribute packet. Called after an XP orb is collected; also
     * part of the login burst so the bar starts at a known state.
     */
    public function syncXpFor(int $entityId): void {
        foreach ($this->sessions as $session) {
            if ($session['playerRef']->entityId !== $entityId) {
                continue;
            }
            $meta = $session['entityRef']->getEntity()?->get(MetadataComponent::class);
            $xpLevel = (int)($meta?->get(MetadataKeys::XP_LEVEL) ?? 0);
            $xp = (int)($meta?->get(MetadataKeys::XP) ?? 0);
            $need = self::xpNeedForLevel($xpLevel);
            $progress = $need > 0 ? min(1.0, $xp / $need) : 0.0;

            $pk = new UpdateAttributesPacket();
            $pk->entityId = 0; // legacy: 0 targets the player's own HUD
            $pk->entries = [
                [0.0, 2147483647.0, (float)$xpLevel, 'player.level'],
                [0.0, 1.0, $progress, 'player.experience'],
            ];
            $this->queuePacket($session['playerRef'], $pk);
            return;
        }
    }

    /**
     * Standard Minecraft XP curve (legacy Human::getXpNeedToNextLevel).
     */
    public static function xpNeedForLevel(int $level): int {
        if ($level <= 15) {
            return 2 * $level + 7;
        }
        if ($level <= 30) {
            return 5 * $level - 38;
        }
        return 9 * $level - 158;
    }

    /**
     * 14.3 respawn: the client sends RespawnPacket from the death screen.
     * Revive the player through PlayerRespawnService (clear DeadTag, restore
     * health, teleport to the safe spawn) and send the respawn burst so the
     * client leaves the death screen at the spawn point.
     */
    private function handleRespawn(string $addrKey, RespawnPacket $pk): void {
        $session = $this->sessions[$addrKey] ?? null;
        if ($session === null) {
            return;
        }
        $health = $session['entityRef']->getEntity()?->get(HealthComponent::class);
        if ($health !== null && $health->current > 0) {
            return; // alive players do not respawn
        }
        $this->playerRespawnService->respawn($session['entityRef']);
        $this->sendRespawnBurst($addrKey);
    }

    private function sendRespawnBurst(string $addrKey): void {
        $session = $this->sessions[$addrKey];
        $playerRef = $session['playerRef'];
        $entity = $session['entityRef']->getEntity();
        $pos = $session['entityRef']->getPosition();
        $health = $entity?->get(HealthComponent::class);

        // PLAYER_SPAWN status dismisses the death screen.
        $status = new PlayStatusPacket();
        $status->status = PlayStatusPacket::PLAYER_SPAWN;
        $this->queuePacket($playerRef, $status);

        // Teleport the client back to the (terrain-safe) spawn point.
        $move = new MovePlayerPacket();
        $move->eid = 0; // protocol 84: the player's own entity is always 0
        $move->x = $pos?->x ?? 0.0;
        $move->y = $pos?->y ?? 0.0;
        $move->z = $pos?->z ?? 0.0;
        $move->yaw = 0.0;
        $move->bodyYaw = 0.0;
        $move->pitch = 0.0;
        $move->mode = MovePlayerPacket::MODE_RESET;
        $move->onGround = true;
        $this->queuePacket($playerRef, $move);
        // Blocker 2: the respawn teleport was server-side - grace the client's
        // converging moves so they are not misread as a speed/fly hack.
        $this->sessions[$addrKey]['teleportGraceTicks'] = 3;

        // Reset the HUD health bar (the per-tick pass also catches the jump).
        $hp = new SetHealthPacket();
        $hp->health = (int)($health?->current ?? 20);
        $this->queuePacket($playerRef, $hp);

        // Point the compass back at spawn.
        $spawn = new SetSpawnPositionPacket();
        $spawn->x = (int)floor($pos?->x ?? 0.0);
        $spawn->y = (int)floor($pos?->y ?? 64.0);
        $spawn->z = (int)floor($pos?->z ?? 0.0);
        $this->queuePacket($playerRef, $spawn);

        // Everyone else sees the player revive (RESPAWN event); the teleport
        // itself is relayed by the per-tick entity state pass.
        $event = new EntityEventPacket();
        $event->eid = $playerRef->entityId;
        $event->event = EntityEventPacket::RESPAWN;
        foreach ($this->sessions as $s) {
            $this->queuePacket($s['playerRef'], clone $event);
        }
    }

    /**
     * Broadcast the authoritative block state at a position to every session.
     * Reads the state from the ChunkStore so whatever the services actually
     * set (including placement-meta resolution) is what the client receives.
     */
    private function broadcastBlockState(int $x, int $y, int $z, int $worldId = 0): void {
        $store = $this->getChunkStore($worldId);
        if ($store === null) {
            return;
        }
        $pk = new UpdateBlockPacket();
        $pk->x = $x;
        $pk->y = $y;
        $pk->z = $z;
        $pk->blockId = $store->getBlock($x, $y, $z);
        $pk->blockData = $store->getBlockMeta($x, $y, $z);
        $pk->flags = UpdateBlockPacket::FLAG_ALL_PRIORITY;
        foreach ($this->sessions as $s) {
            // Only viewers in the same world see the change.
            if ($s['worldId'] === $worldId) {
                $this->queuePacket($s['playerRef'], clone $pk);
            }
        }
    }

    private function isChestBlock(int $x, int $y, int $z, int $worldId = 0): bool {
        $store = $this->getChunkStore($worldId);
        return $store !== null && $store->getBlock($x, $y, $z) === ItemIds::CHEST;
    }

    /**
     * Resolve the ChunkStore for a world id (14.20): non-default worlds have
     * their own store bundle; the default world falls back to the classic
     * resource-registry store so single-world behavior is unchanged.
     */
    private function getChunkStore(int $worldId = 0): ?ChunkStore {
        // Non-default worlds resolve strictly through the registry; only the
        // default world (id 0) falls back to the classic resource-registry
        // store so single-world behavior is unchanged.
        if ($worldId !== 0) {
            $registry = $this->resourceRegistry->get(WorldRegistry::class);
            return $registry instanceof WorldRegistry ? $registry->getStore($worldId) : null;
        }
        $store = $this->resourceRegistry->get(ChunkStore::class);
        return $store instanceof ChunkStore ? $store : null;
    }

    /**
     * Resolve the WorldConfig for a world id (14.20), falling back to the
     * default world config resource.
     */
    private function getWorldConfig(int $worldId = 0): ?WorldConfig {
        if ($worldId !== 0) {
            $registry = $this->resourceRegistry->get(WorldRegistry::class);
            return $registry instanceof WorldRegistry ? $registry->getConfig($worldId) : null;
        }
        $config = $this->resourceRegistry->get(WorldConfig::class);
        return $config instanceof WorldConfig ? $config : null;
    }

    /**
     * A chest block was broken: spill its contents as item entities (so the
     * items are not lost), forget the store entry, and close the window of
     * every session that had it open (the chest is gone).
     */
    private function onChestBroken(int $x, int $y, int $z, array $breaker): void {
        $worldId = $breaker['worldId'] ?? 0;
        $store = $this->chestStore($worldId);
        // 14.27: a broken chest that was part of a double chest spills BOTH
        // halves' contents (the pair is still in the world - only the broken
        // block was removed) and closes every viewer of the pair window.
        $pair = $this->chestPair($x, $y, $z, $worldId);
        $spill = [$x, $z];
        if ($pair !== null) {
            $spill[] = $pair[0];
            $spill[] = $pair[1];
            $spill[] = $pair[2];
            $spill[] = $pair[3];
        }
        for ($i = 0; $i < count($spill); $i += 2) {
            $sx = $spill[$i];
            $sz = $spill[$i + 1];
            if ($sx === $x && $sz === $z) {
                continue; // the broken half is removed below
            }
            $inv = $store->remove($sx, $y, $sz);
            foreach ($inv->getContents() as $item) {
                $this->entitySpawnService->spawnItem($sx + 0.5, $y + 0.5, $sz + 0.5, $item);
            }
        }
        $inv = $store->remove($x, $y, $z);
        foreach ($inv->getContents() as $item) {
            $this->entitySpawnService->spawnItem($x + 0.5, $y + 0.5, $z + 0.5, $item);
        }
        foreach ($this->sessions as $key => $s) {
            $open = $s['openContainer'];
            if ($open === null || $s['worldId'] !== $worldId || $open['type'] !== 'chest') {
                continue;
            }
            // Close viewers of this chest or of its pair.
            $matches = $open['x'] === $x && $open['y'] === $y && $open['z'] === $z;
            if ($pair !== null) {
                $matches = ($open['x'] === $pair[0] && $open['z'] === $pair[1])
                    || ($open['x'] === $pair[2] && $open['z'] === $pair[3]);
            }
            if ($matches) {
                $s['openContainer'] = null;
                $this->sessions[$key] = $s;
                $close = new ContainerClosePacket();
                $close->windowid = self::CHEST_WINDOW_ID;
                $this->queuePacket($s['playerRef'], $close);
            }
        }
    }

    /**
     * Send one inventory slot, translating the inventory slot back to the
     * right wire window: 0-35 -> window 0, 36-39 -> armor window (0x78).
     */
    private function sendInventorySlot(PlayerRef $player, int $slot): void {
        $addrKey = $this->addrKeyForPlayer($player);
        $session = $addrKey !== null ? ($this->sessions[$addrKey] ?? null) : null;
        if ($session === null) {
            return;
        }
        $inventory = $session['entityRef']->getEntity()?->get(InventoryComponent::class);
        if ($inventory === null) {
            return;
        }
        $item = $inventory->get($slot);
        $pk = new ContainerSetSlotPacket();
        if ($slot >= InventoryComponent::ARMOR_OFFSET) {
            $pk->windowid = ContainerSetContentPacket::SPECIAL_ARMOR;
            $pk->slot = $slot - InventoryComponent::ARMOR_OFFSET;
            $pk->hotbarSlot = $pk->slot; // legacy ArmorInventory::sendSlot parity
        } else {
            $pk->windowid = ContainerSetContentPacket::SPECIAL_INVENTORY;
            $pk->slot = $slot;
            // For the player's own window the hotbar mapping is the identity
            // for hotbar slots 0-8 (all current callers use held hotbar
            // slots); main inventory slots (9+) would need the real mapping.
            $pk->hotbarSlot = $slot;
        }
        $pk->item = $item !== null ? [$item->itemId, $item->count, $item->meta, $item->nbt] : [0, 0, 0, null];
        $this->queuePacket($player, $pk);
    }

    /**
     * A craft request from the client. The packet carries the crafting grid
     * contents (input) and the claimed result (output). The grid is validated
     * against the RecipeRegistry by shape (RecipeRegistry::matchShaped), the
     * ingredients are consumed from the player inventory and the result is
     * added - all through CraftingService. The window is then resynced so the
     * client's counts stay authoritative even when a craft is rejected.
     */
    private function handleCraftingEvent(string $addrKey, CraftingEventPacket $pk): void {
        $session = $this->sessions[$addrKey] ?? null;
        if ($session === null) {
            return;
        }
        $entity = $session['entityRef']->getEntity();
        if ($entity === null) {
            return;
        }
        // windowId 0x79 = player 2x2 crafting grid; 0x7e = crafting table
        // (3x3). The type field mirrors this (0 = small, 1 = big) but the
        // window id is the reliable discriminator on the 0.15.10 wire.
        $gridWidth = ($pk->windowId === CraftingEventPacket::WINDOW_CRAFTING_TABLE || $pk->type === CraftingEventPacket::TYPE_BIG) ? 3 : 2;
        $cells = $gridWidth * $gridWidth;

        $grid = [];
        foreach (array_slice($pk->input, 0, $cells) as $slot) {
            $id = (int)$slot[0];
            if ($id <= 0) {
                $grid[] = null;
                continue;
            }
            $grid[] = new ItemStack($id, (int)$slot[2], 1, null);
        }
        while (count($grid) < $cells) {
            $grid[] = null; // pad a short grid with empty cells
        }

        $this->craftingService->craft($session['entityRef'], $grid, $gridWidth);
        // Resync regardless of outcome: on success the ingredients are gone
        // and the result is in the inventory; on failure this undoes any
        // client-side grid desync.
        $this->sendInventoryContents($session['playerRef']);
    }

    /** Send the full inventory contents (window 0) to the player. */
    private function sendInventoryContents(PlayerRef $player): void {
        $addrKey = $this->addrKeyForPlayer($player);
        $session = $addrKey !== null ? ($this->sessions[$addrKey] ?? null) : null;
        if ($session === null) {
            return;
        }
        $inventory = $session['entityRef']->getEntity()?->get(InventoryComponent::class);
        if ($inventory === null) {
            return;
        }
        $pk = new ContainerSetContentPacket();
        $pk->windowid = ContainerSetContentPacket::SPECIAL_INVENTORY;
        $pk->slots = [];
        // Window 0 is 36 slots; armor slots (36-39) ride in the 0x78 window.
        for ($i = 0; $i < min($inventory->size, InventoryComponent::ARMOR_OFFSET); $i++) {
            $item = $inventory->get($i);
            $pk->slots[] = $item !== null ? [$item->itemId, $item->count, $item->meta, $item->nbt] : [0, 0, 0, null];
        }
        $this->queuePacket($player, $pk);
    }

    // --- Armor (14.13) -------------------------------------------------------

    /** Send the 4 armor slots as the armor window (0x78) to the player. */
    private function sendArmorContents(PlayerRef $player): void {
        $addrKey = $this->addrKeyForPlayer($player);
        $session = $addrKey !== null ? ($this->sessions[$addrKey] ?? null) : null;
        if ($session === null) {
            return;
        }
        $inventory = $session['entityRef']->getEntity()?->get(InventoryComponent::class);
        if ($inventory === null) {
            return;
        }
        $pk = new ContainerSetContentPacket();
        $pk->windowid = ContainerSetContentPacket::SPECIAL_ARMOR;
        $pk->slots = [];
        for ($i = 0; $i < 4; $i++) {
            $item = $inventory->get(InventoryComponent::ARMOR_OFFSET + $i);
            $pk->slots[] = $item !== null ? [$item->itemId, $item->count, $item->meta, $item->nbt] : [0, 0, 0, null];
        }
        $this->queuePacket($player, $pk);
    }

    /** Broadcast a player's equipped gear to every session (actor included). */
    private function sendMobArmorToAll(int $entityId): void {
        $inventory = null;
        foreach ($this->sessions as $session) {
            if ($session['playerRef']->entityId === $entityId) {
                $inventory = $session['entityRef']->getEntity()?->get(InventoryComponent::class);
                break;
            }
        }
        if ($inventory === null) {
            return;
        }
        $pk = new MobArmorEquipmentPacket();
        $pk->eid = $entityId;
        for ($i = 0; $i < 4; $i++) {
            $item = $inventory->get(InventoryComponent::ARMOR_OFFSET + $i);
            $pk->slots[$i] = $item !== null ? [$item->itemId, $item->count, $item->meta, $item->nbt] : [0, 0, 0, null];
        }
        foreach ($this->sessions as $session) {
            $this->queuePacket($session['playerRef'], $pk);
        }
    }

    /**
     * 14.13: reflect an armor change to everyone. The actor gets its armor
     * window (0x78) refreshed; every session gets a MobArmorEquipmentPacket so
     * other players see the equipped gear. Public because the wear-on-hit
     * path (CombatService) has no session access - it resolves the session
     * service lazily and calls this after degrading or breaking a piece.
     */
    public function syncArmorFor(int $entityId): void {
        foreach ($this->sessions as $session) {
            if ($session['playerRef']->entityId === $entityId) {
                $this->sendArmorContents($session['playerRef']);
                break;
            }
        }
        $this->sendMobArmorToAll($entityId);
    }

    /**
     * 14.13: notify the client that armor absorbed a hit (HurtArmorPacket -
     * the client flashes the armor damage indicator). Public for the
     * wear-on-hit path in CombatService.
     */
    public function sendHurtArmorFor(int $entityId, int $health): void {
        foreach ($this->sessions as $session) {
            if ($session['playerRef']->entityId !== $entityId) {
                continue;
            }
            $pk = new HurtArmorPacket();
            $pk->health = max(0, $health);
            $this->queuePacket($session['playerRef'], $pk);
            return;
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
        $worldConfig = $this->getWorldConfig($session['worldId']);

        $playStatus = new PlayStatusPacket();
        $playStatus->status = PlayStatusPacket::LOGIN_SUCCESS;
        $this->queuePacket($playerRef, $playStatus);

        $startGame = new StartGamePacket();
        // 14.20: the world the player is in provides seed (terrain must match
        // what the chunk stream delivers), not the server-wide config.
        $startGame->seed = $worldConfig?->seed ?? ($config instanceof ServerConfig ? $config->getSeed() : 0);
        $startGame->dimension = 0;
        $startGame->generator = 1;
        // Gamemode from the player's metadata (saved on disconnect, or 0 for
        // fresh players). The client needs the initial mode to render the
        // correct UI (survival: hotbar, creative: flight toggle).
        $startGame->gamemode = GameMode::coerce($entity?->get(\pocketmine\core\component\MetadataComponent::class)?->get(MetadataKeys::GAMEMODE))->value;
        $startGame->eid = 0; // protocol 84 always uses entity id 0 for the player
        $startGame->spawnX = $spawnX;
        $startGame->spawnY = $spawnY;
        $startGame->spawnZ = $spawnZ;
        $startGame->x = $pos?->x ?? 0.0;
        $startGame->y = $pos?->y ?? 64.0;
        $startGame->z = $pos?->z ?? 0.0;
        $this->queuePacket($playerRef, $startGame);

        $time = new SetTimePacket();
        $time->time = $worldConfig?->time ?? 0;
        $time->started = true;
        $this->queuePacket($playerRef, $time);

        // 14.22: hand the newcomer the current weather so rain/storms render
        // immediately (legacy Weather::sendWeather on join).
        $this->sendWeatherState($playerRef, $worldConfig?->weather ?? 0);

        $spawn = new SetSpawnPositionPacket();
        $spawn->x = $spawnX;
        $spawn->y = $spawnY;
        $spawn->z = $spawnZ;
        $this->queuePacket($playerRef, $spawn);

        $hp = new SetHealthPacket();
        $hp->health = (int)($health?->current ?? 20);
        $this->queuePacket($playerRef, $hp);

        $difficulty = new SetDifficultyPacket();
        $difficulty->difficulty = $config instanceof ServerConfig ? $config->difficulty->value : Difficulty::Easy->value;
        $this->queuePacket($playerRef, $difficulty);

        $settings = new AdventureSettingsPacket();
        $settings->flags = 0x02 | 0x04 | 0x08 | 0x40; // no pvp/pvm/pve + auto jump
        $settings->userPermission = 2;
        $settings->globalPermission = 2;
        $this->queuePacket($playerRef, $settings);

        // Full inventory contents (window 0) so the client renders the
        // hotbar with the player's actual items (starter kit for new players).
        $this->sendInventoryContents($playerRef);
        // 14.13: armor window contents (0x78) + the equipped gear broadcast
        // (MobArmorEquipmentPacket) so other players see it right away.
        $this->sendArmorContents($playerRef);
        $this->sendMobArmorToAll($session['playerRef']->entityId);

        // 14.9: init the XP bar (level 0, empty progress).
        $this->syncXpFor($session['playerRef']->entityId);
        // 14.11: init the food bars (full hunger, default saturation).
        $this->syncFoodFor($session['playerRef']->entityId);
        // 14.12: the client needs the recipe list to render the crafting UI
        // (legacy sent it in Server::onPlayerLogin, right after the burst).
        $this->sendCraftingData($playerRef);
        // Creative inventory (window 0x79): populate the client's item picker
        // so creative players can take any item (legacy sendContents parity).
        $this->sendCreativeContents($playerRef);
    }

    /**
     * Send the creative inventory (ContainerSetContentPacket, window 0x79) so
     * the client's item picker shows the curated vanilla item list. Picks come
     * back as ContainerSetSlotPacket with the same window id.
     */
    private function sendCreativeContents(PlayerRef $player): void {
        $pk = new ContainerSetContentPacket();
        $pk->windowid = ContainerSetContentPacket::SPECIAL_CREATIVE;
        $pk->slots = \pocketmine\core\resource\CreativeItems::all();
        $this->queuePacket($player, $pk);
    }

    /**
     * Send the full recipe list (CraftingDataPacket) so the client can render
     * crafting results. Recipes come from the RecipeRegistry (registered in
     * Kernel::registerBuiltinRecipes); every shaped recipe is one wire entry.
     */
    private function sendCraftingData(PlayerRef $player): void {
        $registry = $this->resourceRegistry->get(\pocketmine\core\resource\RecipeRegistry::class);
        if (!$registry instanceof \pocketmine\core\resource\RecipeRegistry) {
            return;
        }
        $pk = new CraftingDataPacket();
        $pk->recipes = $registry->getShapedRecipes();
        $this->queuePacket($player, $pk);
    }

    /**
     * 14.6: push the current world time to every connected player so the
     * day/night cycle renders on the client (sun set, moon rise, sky tint).
     * Called every poll; SetTimePacket is tiny (5 bytes) and rides the
     * existing per-session outbound batch.
     */
    private function broadcastTime(): void {
        if (empty($this->sessions)) {
            return;
        }
        // 14.20: each session receives the time of the world it is in, so
        // players in different worlds see different day/night cycles.
        foreach ($this->sessions as $session) {
            $config = $this->getWorldConfig($session['worldId']);
            $pk = new SetTimePacket();
            $pk->time = $config?->time ?? 0;
            $pk->started = true;
            $this->queuePacket($session['playerRef'], $pk);
        }
    }

    /**
     * 14.22: push weather state changes to every connected player. The client
     * only needs packets on transitions (legacy Weather::sendWeatherToAll on
     * setWeather): rain/storm state is encoded as two LevelEventPackets - one
     * for rain (START/STOP 3001/3003) and one for thunder (START/STOP
     * 3002/3004) - each carrying the legacy particle-strength ints. During
     * storms the WeatherSystem advances WorldConfig::lightningTick; when it
     * crosses LIGHTNING_INTERVAL a bolt strikes near a random player in that
     * world (AddEntityPacket type 93), exactly like legacy calcWeather.
     */
    private function broadcastWeather(): void {
        if (empty($this->sessions)) {
            return;
        }
        $struck = []; // worldId => already struck this tick
        foreach ($this->sessions as $addrKey => $session) {
            $config = $this->getWorldConfig($session['worldId']);
            if ($config === null) {
                continue;
            }
            $weather = $config->weather;
            if ($session['lastWeather'] !== $weather) {
                $this->sendWeatherState($session['playerRef'], $weather);
                $session['lastWeather'] = $weather;
                $this->sessions[$addrKey] = $session;
            }
            // Lightning: once per interval during a storm, near a random
            // player of that world (one strike per world per tick).
            if (\pocketmine\core\system\WeatherSystem::isThundering($weather)
                && $config->lightningTick > 0
                && $config->lightningTick % \pocketmine\core\system\WeatherSystem::LIGHTNING_INTERVAL === 0
                && !isset($struck[$session['worldId']])
            ) {
                $this->strikeLightning($session['worldId']);
                $struck[$session['worldId']] = true;
            }
        }
    }

    /**
     * The two legacy LevelEventPackets that describe the current weather to
     * one client (identical layout to old Weather::sendWeather).
     */
    private function sendWeatherState(PlayerRef $player, int $weather): void {
        $strength1 = mt_rand(90000, 110000);
        $strength2 = mt_rand(30000, 40000);

        $rain = new LevelEventPacket();
        $rain->evid = \pocketmine\core\system\WeatherSystem::isRaining($weather)
            ? LevelEventPacket::EVENT_START_RAIN
            : LevelEventPacket::EVENT_STOP_RAIN;
        $rain->data = $strength1;
        $this->queuePacket($player, $rain);

        $thunder = new LevelEventPacket();
        $thunder->evid = \pocketmine\core\system\WeatherSystem::isThundering($weather)
            ? LevelEventPacket::EVENT_START_THUNDER
            : LevelEventPacket::EVENT_STOP_THUNDER;
        $thunder->data = $strength2;
        $this->queuePacket($player, $thunder);
    }

    /**
     * A lightning bolt at the highest solid block near a random online player
     * of the given world, broadcast to everyone in that world (legacy
     * Level::spawnLightning - visual entity only, no fire damage in 0.15).
     */
    private function strikeLightning(int $worldId): void {
        $targets = [];
        foreach ($this->sessions as $session) {
            if ($session['worldId'] !== $worldId) {
                continue;
            }
            $pos = $session['entityRef']->getPosition();
            if ($pos !== null) {
                $targets[] = $pos;
            }
        }
        if ($targets === []) {
            return;
        }
        $near = $targets[array_rand($targets)];
        $x = (float)((int)$near->x + mt_rand(-64, 64));
        $z = (float)((int)$near->z + mt_rand(-64, 64));
        $store = $this->resourceRegistry->get(\pocketmine\core\resource\ChunkStore::class);
        $y = $store instanceof \pocketmine\core\resource\ChunkStore
            ? (float)$store->getHighestBlockAt((int)$x, (int)$z)
            : 64.0;

        $pk = new AddEntityPacket();
        $pk->eid = mt_rand(10000000, 100000000);
        $pk->type = 93; // Lightning (legacy entity network id)
        $pk->x = $x;
        $pk->y = $y;
        $pk->z = $z;
        $pk->metadata = [];
        foreach ($this->sessions as $session) {
            if ($session['worldId'] === $worldId) {
                $this->queuePacket($session['playerRef'], clone $pk);
            }
        }
    }

    private function broadcastPlayerListAdd(UUID $uuid, int $entityId, string $username, string $skin): void {
        $list = new PlayerListPacket();
        $list->type = PlayerListPacket::TYPE_ADD;
        $list->entries = [[$uuid, $entityId, $username, '0', $skin]];
        foreach ($this->sessions as $s) {
            $this->queuePacket($s['playerRef'], clone $list);
        }
    }

    /**
     * Hand the newcomer the player-list entries of everyone already online
     * (their own entry was broadcast by broadcastPlayerListAdd). The client
     * needs these before the per-tick entity sync adds the other players as
     * entities, or their skins/names would not render.
     */
    private function sendExistingPlayerList(string $newAddrKey): void {
        $entries = [];
        foreach ($this->sessions as $addrKey => $session) {
            if ($addrKey === $newAddrKey) {
                continue;
            }
            $entries[] = [$session['uuid'], $session['playerRef']->entityId, $session['username'], '0', $session['skin']];
        }
        if (empty($entries)) {
            return;
        }
        $list = new PlayerListPacket();
        $list->type = PlayerListPacket::TYPE_ADD;
        $list->entries = $entries;
        $this->queuePacket($this->sessions[$newAddrKey]['playerRef'], $list);
    }

    // --- Entity broadcasting (14.2) -----------------------------------------

    /**
     * Per-tick mirror of live world entities to every session. For each
     * viewer we keep a knownEntities set (entityId => last broadcast
     * position); an entity that enters view range is added (AddPlayerPacket /
     * AddEntityPacket / AddItemEntityPacket), one that moves is followed
     * (MovePlayerPacket / MoveEntityPacket), and one that leaves range or
     * despawns is removed (RemoveEntityPacket). This is the classic legacy
     * spawnTo/despawnFrom pattern expressed against the ECS.
     */
    private function broadcastEntityStates(): void {
        $worldEntities = $this->world->getEntities();
        // entityId => session: players use AddPlayerPacket/MovePlayerPacket
        // and their identity comes from session state, not ECS metadata.
        $playerSessions = [];
        foreach ($this->sessions as $addrKey => $session) {
            $playerSessions[$session['playerRef']->entityId] = $addrKey;
        }

        foreach ($this->sessions as $addrKey => $session) {
            $center = $session['entityRef']->getPosition();
            if ($center === null) {
                continue;
            }
            $rangeSq = ($session['radius'] * 16) ** 2; // view distance in blocks
            $known = $session['knownEntities'];
            $selfId = $session['playerRef']->entityId;

            // Visible set for this viewer: all entities within range except self.
            $visible = [];
            $worldId = $session['worldId'];
            foreach ($worldEntities as $entityId => $entity) {
                if ($entityId === $selfId) {
                    continue;
                }
                // 14.20: only entities of the viewer's own world are visible
                // (players in other worlds do not render across worlds).
                // Entities without a WorldComponent default to world 0 so a
                // stray spawn never leaks across worlds.
                $entityWorld = $entity->get(WorldComponent::class);
                $entityWorldId = $entityWorld instanceof WorldComponent ? $entityWorld->id : 0;
                if ($entityWorldId !== $worldId) {
                    continue;
                }
                $pos = $entity->get(PositionComponent::class);
                if ($pos === null) {
                    continue;
                }
                $dx = $pos->x - $center->x;
                $dy = $pos->y - $center->y;
                $dz = $pos->z - $center->z;
                if ($dx * $dx + $dy * $dy + $dz * $dz <= $rangeSq) {
                    $visible[$entityId] = $entity;
                }
            }

            // Removals: left view range or despawned from the world.
            foreach ($known as $entityId => $lastPos) {
                if (!isset($visible[$entityId])) {
                    $rm = new RemoveEntityPacket();
                    $rm->eid = $entityId;
                    $this->queuePacket($session['playerRef'], $rm);
                    unset($known[$entityId]);
                }
            }

            // Adds + moves: a fresh entity is added once (with the legacy
            // default metadata dict), then followed with movement packets
            // only while it actually moves. Protocol 84 has no per-viewer
            // health metadata - the player's own HUD is driven by
            // SetHealthPacket below, and mob health bars are client-side.
            foreach ($visible as $entityId => $entity) {
                $pos = $entity->get(PositionComponent::class);
                if ($pos === null) {
                    continue;
                }
                if (!isset($known[$entityId])) {
                    $pk = $this->buildAddPacket($entityId, $entity, $playerSessions);
                    if ($pk !== null) {
                        $this->queuePacket($session['playerRef'], $pk);
                        // Legacy Item::spawnTo sent AddItemEntityPacket followed
                        // by SetEntityDataPacket with the full default data dict.
                        // The 0.15 client needs DATA_LEAD_HOLDER (23) and
                        // DATA_LEAD (24) sent explicitly or it renders a
                        // leash/rope attached to the dropped item.
                        if ($pk instanceof AddItemEntityPacket) {
                            $this->queuePacket($session['playerRef'], $this->buildEntityDataPacket($entityId, $this->legacyMetadataDefaults()));
                        }
                    }
                    $known[$entityId] = [$pos->x, $pos->y, $pos->z];
                } else {
                    $last = $known[$entityId];
                    $moved = abs($pos->x - $last[0]) > self::MOVE_EPSILON
                        || abs($pos->y - $last[1]) > self::MOVE_EPSILON
                        || abs($pos->z - $last[2]) > self::MOVE_EPSILON;
                    if ($moved) {
                        $this->queuePacket($session['playerRef'], $this->buildMovePacket($entityId, $entity, $playerSessions));
                        $known[$entityId] = [$pos->x, $pos->y, $pos->z];
                    }
                }
            }

            // The player's own health bar: SetHealthPacket drives the HUD
            // hearts (legacy protocol-84 behaviour), following damage/heal/
            // respawn without waiting for a client re-sync.
            $selfHealth = $session['entityRef']->getEntity()?->get(HealthComponent::class)?->current ?? 20.0;
            if (abs($selfHealth - $session['lastHealth']) > 0.01) {
                $hp = new SetHealthPacket();
                $hp->health = (int)ceil($selfHealth);
                $this->queuePacket($session['playerRef'], $hp);
            }
            $session['lastHealth'] = $selfHealth;

            $session['knownEntities'] = $known;
            $this->sessions[$addrKey] = $session;
        }
    }

    /**
     * The right Add packet for an entity, or null for an entity type we do
     * not know how to represent (so we never leak garbage ids to clients).
     */
    private function buildAddPacket(int $entityId, Entity $entity, array $playerSessions): ?DataPacket {
        if (isset($playerSessions[$entityId])) {
            return $this->buildAddPlayerPacket($playerSessions[$entityId]);
        }
        $meta = $entity->get(MetadataComponent::class);
        $item = $meta?->get(MetadataKeys::ITEM);
        if ($item instanceof ItemStack) {
            $pk = new AddItemEntityPacket();
            $pk->eid = $entityId;
            $pk->item = [$item->itemId, $item->count, $item->meta, $item->nbt];
            $pos = $entity->get(PositionComponent::class);
            $vel = $entity->get(VelocityComponent::class);
            $pk->x = $pos?->x ?? 0.0;
            $pk->y = $pos?->y ?? 0.0;
            $pk->z = $pos?->z ?? 0.0;
            $pk->speedX = $vel?->x ?? 0.0;
            $pk->speedY = $vel?->y ?? 0.0;
            $pk->speedZ = $vel?->z ?? 0.0;
            return $pk;
        }
        // 14.9: XP orbs render as legacy XPOrb (network id 69) with the
        // DATA_NO_AI flag so the client does not give them mob AI behaviour.
        if ($meta?->get(MetadataKeys::XP) !== null && $entity->has(\pocketmine\core\constants\EntityTags::XP_ORB)) {
            $pk = new AddEntityPacket();
            $pk->eid = $entityId;
            $pk->type = 69; // legacy XPOrb::NETWORK_ID
            $pos = $entity->get(PositionComponent::class);
            $vel = $entity->get(VelocityComponent::class);
            $pk->x = $pos?->x ?? 0.0;
            $pk->y = $pos?->y ?? 0.0;
            $pk->z = $pos?->z ?? 0.0;
            $pk->speedX = $vel?->x ?? 0.0;
            $pk->speedY = $vel?->y ?? 0.0;
            $pk->speedZ = $vel?->z ?? 0.0;
            $pk->metadata = $this->legacyMetadataDefaults();
            $pk->metadata[15] = [Binary::DATA_TYPE_BYTE, 1]; // DATA_NO_AI
            return $pk;
        }
        // Projectiles render through the projectile registry (14.18): the
        // network id comes from the registry, never hard-coded, so future
        // projectile types (snowballs, eggs, ...) render without touching
        // this method. Unregistered types return null so we never leak
        // garbage entity ids to clients.
        $projectileType = $meta?->get(MetadataKeys::PROJECTILE_TYPE);
        if (is_string($projectileType)) {
            $projectiles = $this->resourceRegistry->get(ProjectileRegistry::class);
            $networkId = $projectiles instanceof ProjectileRegistry ? $projectiles->getNetworkId($projectileType) : null;
            if ($networkId === null) {
                return null;
            }
            $pk = new AddEntityPacket();
            $pk->eid = $entityId;
            $pk->type = $networkId;
            $pos = $entity->get(PositionComponent::class);
            $vel = $entity->get(VelocityComponent::class);
            $rot = $entity->get(RotationComponent::class);
            $pk->x = $pos?->x ?? 0.0;
            $pk->y = $pos?->y ?? 0.0;
            $pk->z = $pos?->z ?? 0.0;
            $pk->speedX = $vel?->x ?? 0.0;
            $pk->speedY = $vel?->y ?? 0.0;
            $pk->speedZ = $vel?->z ?? 0.0;
            $pk->yaw = $rot?->yaw ?? 0.0;
            $pk->pitch = $rot?->pitch ?? 0.0;
            $pk->metadata = $this->legacyMetadataDefaults();
            // DATA_SHOOTER_ID (17): lets the client render the pulled-back bow
            // for the shooter (legacy Projectile::DATA_SHOOTER_ID).
            $pk->metadata[17] = [\pocketmine\utils\Binary::DATA_TYPE_LONG, (int)$meta->get(MetadataKeys::SHOOTER_ID, 0)];
            return $pk;
        }
        $type = EntityType::tryFrom((string)($meta?->get(MetadataKeys::MOB_TYPE) ?? $meta?->get(MetadataKeys::ENTITY_TYPE) ?? ''));
        $networkId = $type?->networkId();
        if ($networkId === null) {
            return null;
        }
        $pk = new AddEntityPacket();
        $pk->eid = $entityId;
        $pk->type = $networkId;
        $pos = $entity->get(PositionComponent::class);
        $rot = $entity->get(RotationComponent::class);
        $vel = $entity->get(VelocityComponent::class);
        $pk->x = $pos?->x ?? 0.0;
        $pk->y = $pos?->y ?? 0.0;
        $pk->z = $pos?->z ?? 0.0;
        $pk->speedX = $vel?->x ?? 0.0;
        $pk->speedY = $vel?->y ?? 0.0;
        $pk->speedZ = $vel?->z ?? 0.0;
        $pk->yaw = $rot?->yaw ?? 0.0;
        $pk->pitch = $rot?->pitch ?? 0.0;
        $pk->metadata = $this->legacyMetadataDefaults();
        // Override the nametag with the mob's type name (legacy mobs carried
        // their type as the nametag string).
        $pk->metadata[2] = [\pocketmine\utils\Binary::DATA_TYPE_STRING, $type->value];
        // 14.25: a vehicle with a rider ships its link in the Add packet so a
        // viewer who first sees the vehicle mid-ride renders the passenger
        // immediately (legacy Vehicle::spawnTo included the link).
        if ($entity->has(\pocketmine\core\constants\EntityTags::VEHICLE)) {
            $riderId = (int)($meta?->get(\pocketmine\core\constants\MetadataKeys::VEHICLE_RIDER_ID) ?? 0);
            if ($riderId > 0) {
                $pk->links[] = [$entityId, $riderId, SetEntityLinkPacket::TYPE_RIDE];
            }
        }
        return $pk;
    }

    private function buildAddPlayerPacket(string $addrKey): AddPlayerPacket {
        $session = $this->sessions[$addrKey];
        $entity = $session['entityRef']->getEntity();
        $pos = $session['entityRef']->getPosition();
        $rot = $session['entityRef']->getRotation();
        $vel = $session['entityRef']->getVelocity();
        $inv = $entity?->get(InventoryComponent::class);
        $held = $inv?->get($inv->heldSlot);
        $pk = new AddPlayerPacket();
        $pk->uuid = $session['uuid'];
        $pk->username = $session['username'];
        $pk->eid = $session['playerRef']->entityId;
        $pk->x = $pos?->x ?? 0.0;
        $pk->y = $pos?->y ?? 0.0;
        $pk->z = $pos?->z ?? 0.0;
        $pk->speedX = $vel?->x ?? 0.0;
        $pk->speedY = $vel?->y ?? 0.0;
        $pk->speedZ = $vel?->z ?? 0.0;
        $pk->yaw = $rot?->yaw ?? 0.0;
        $pk->pitch = $rot?->pitch ?? 0.0;
        $pk->item = $held !== null ? [$held->itemId, $held->count, $held->meta, $held->nbt] : [0, 0, 0, null];
        $pk->metadata = $this->legacyMetadataDefaults();
        $pk->metadata[2] = [\pocketmine\utils\Binary::DATA_TYPE_STRING, $session['username']];
        return $pk;
    }

    /**
     * A SetEntityDataPacket carrying an arbitrary metadata dict.
     */
    private function buildEntityDataPacket(int $entityId, array $metadata): SetEntityDataPacket {
        $pk = new SetEntityDataPacket();
        $pk->eid = $entityId;
        $pk->metadata = $metadata;
        return $pk;
    }

    /**
     * The legacy default entity-data dict (old-src Entity::getDefaultData),
     * sent with every Add* packet. Protocol 84 has NO health metadata key:
     * key 1 is DATA_AIR (SHORT) - writing health there as an INT corrupts the
     * entity's air. Keys 23/24 are the lead holder + leash flag; the 0.15
     * client renders a rope on any entity that does not receive them
     * explicitly, so legacy always shipped them (holder -1, leash 0).
     *
     * @return array<int, array{0: int, 1: mixed}>
     */
    private function legacyMetadataDefaults(): array {
        return [
            0 => [Binary::DATA_TYPE_BYTE, 0],       // DATA_FLAGS
            1 => [Binary::DATA_TYPE_SHORT, 300],    // DATA_AIR
            2 => [Binary::DATA_TYPE_STRING, ''],    // DATA_NAMETAG (overridden)
            23 => [Binary::DATA_TYPE_LONG, -1],     // DATA_LEAD_HOLDER
            24 => [Binary::DATA_TYPE_BYTE, 0],      // DATA_LEAD
        ];
    }

    /** The right Move packet for an entity (players vs mobs/items). */
    private function buildMovePacket(int $entityId, Entity $entity, array $playerSessions): DataPacket {
        $pos = $entity->get(PositionComponent::class);
        $rot = $entity->get(RotationComponent::class);
        if (isset($playerSessions[$entityId])) {
            $pk = new MovePlayerPacket();
            $pk->eid = $entityId;
            $pk->x = $pos?->x ?? 0.0;
            $pk->y = $pos?->y ?? 0.0;
            $pk->z = $pos?->z ?? 0.0;
            $pk->yaw = $rot?->yaw ?? 0.0;
            $pk->bodyYaw = $rot?->yaw ?? 0.0;
            $pk->pitch = $rot?->pitch ?? 0.0;
            $pk->mode = MovePlayerPacket::MODE_NORMAL;
            $pk->onGround = true;
            return $pk;
        }
        $pk = new MoveEntityPacket();
        $pk->eid = $entityId;
        $pk->x = $pos?->x ?? 0.0;
        $pk->y = $pos?->y ?? 0.0;
        $pk->z = $pos?->z ?? 0.0;
        $pk->yaw = $rot?->yaw ?? 0.0;
        $pk->headYaw = $rot?->yaw ?? 0.0;
        $pk->pitch = $rot?->pitch ?? 0.0;
        return $pk;
    }

    private function queueChunks(string $addrKey): void {
        $session = $this->sessions[$addrKey];
        $pos = $session['entityRef']->getPosition();
        if ($pos === null) {
            return;
        }
        $centerX = (int)floor($pos->x / 16);
        $centerZ = (int)floor($pos->z / 16);
        $session['lastChunkX'] = $centerX;
        $session['lastChunkZ'] = $centerZ;
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
                $chunkData = $this->chunkLoadService->loadChunk($chunkX, $chunkZ, $session['worldId']);

                $chunk = new FullChunkDataPacket();
                $chunk->chunkX = $chunkX;
                $chunk->chunkZ = $chunkZ;
                $chunk->order = FullChunkDataPacket::ORDER_LAYERED;
                $chunk->data = ChunkSerializer::serialize($chunkData);
                $this->sendChunkBatch($addrKey, $chunk);

                // 14.24: after the chunk, send tile-entity data (sign text,
                // item frame contents) so the client renders them (legacy
                // Spawnable::spawnTo per chunk viewer).
                $this->sendChunkTiles($session['playerRef'], $chunkX, $chunkZ, $session['worldId']);

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

    /**
     * 14.24: queue a BlockEntityDataPacket for every sign/frame tile entity
     * inside a freshly streamed chunk so the client renders them immediately
     * (legacy Spawnable::spawnTo). Only tiles with data are sent (an empty
     * frame sends nothing; the client renders the block itself).
     */
    private function sendChunkTiles(PlayerRef $player, int $chunkX, int $chunkZ, int $worldId = 0): void {
        $tiles = $this->tileEntityStore($worldId);
        if ($tiles === null) {
            return;
        }
        foreach ($this->allTilesInChunk($tiles, $chunkX, $chunkZ) as [$x, $y, $z]) {
            $payload = $this->tileEntityPayload($x, $y, $z, $worldId);
            if ($payload === null) {
                continue;
            }
            $pk = new BlockEntityDataPacket();
            $pk->x = $x;
            $pk->y = $y;
            $pk->z = $z;
            $pk->namedtag = $payload;
            $this->queuePacket($player, $pk);
        }
    }

    /**
     * @return list<array{0: int, 1: int, 2: int}> tile positions in a chunk.
     */
    private function allTilesInChunk(TileEntityStore $tiles, int $chunkX, int $chunkZ): array {
        $out = [];
        foreach ($tiles->snapshotsForChunk($chunkX, $chunkZ) as $snapshot) {
            $out[] = [$snapshot->x, $snapshot->y, $snapshot->z];
        }
        return $out;
    }

    /**
     * Legacy Network::$BATCH_THRESHOLD: packets at/above this encoded size are
     * sent as a 0xfe-prefixed compressed batch (full chunks); smaller packets
     * ride their own 0xfe-prefixed frame, exactly like the old
     * RakLibInterface::putPacket.
     */
    private const BATCH_THRESHOLD = 512;

    private function flushOutbound(): void {
        if ($this->adapter === null) {
            return;
        }
        foreach ($this->outbound as $addrKey => $packets) {
            $session = $this->sessions[$addrKey] ?? null;
            if ($session === null || empty($packets)) {
                continue;
            }
            $small = [];
            $large = [];
            foreach ($packets as $packet) {
                $packet->encode();
                $buffer = $packet->getBuffer();
                if (strlen($buffer) >= self::BATCH_THRESHOLD) {
                    $large[] = $buffer;
                } else {
                    $small[] = $buffer;
                }
            }
            // Small packets: one 0xfe-prefixed frame each (RELIABLE_ORDERED).
            foreach ($small as $buffer) {
                $this->adapter->sendGameFrame($addrKey, chr(0xfe) . $buffer);
            }
            // Large packets: a 0xfe-prefixed compressed batch per packet so a
            // full chunk survives RakNet fragmentation in one logical frame.
            foreach ($large as $buffer) {
                $inner = pack('N', strlen($buffer)) . $buffer;
                $compressed = zlib_encode($inner, ZLIB_ENCODING_DEFLATE, 7);
                if ($compressed === false) {
                    continue;
                }
                $batch = new BatchPacket();
                $batch->payload = $compressed;
                $batch->encode();
                $this->adapter->sendGameFrame($addrKey, chr(0xfe) . $batch->getBuffer());
            }
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
    private function sendChunkBatch(string $addrKey, FullChunkDataPacket $chunk): void {
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
        // A chunk is large: 0xfe-prefixed compressed batch (legacy parity).
        $this->adapter->sendGameFrame($addrKey, chr(0xfe) . $batch->getBuffer());
    }

    /** Immediate send to an address, for pre-session replies (login failed). */
    private function sendDirectToAddress(string $addrKey, DataPacket $packet): void {
        if ($this->adapter === null) {
            return;
        }
        $packet->encode();
        $this->adapter->sendGameFrame($addrKey, chr(0xfe) . $packet->getBuffer());
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

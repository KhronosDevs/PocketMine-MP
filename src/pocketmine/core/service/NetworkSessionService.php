<?php

declare(strict_types=1);

namespace pocketmine\core\service;

use pocketmine\adapter\driven\network\Protocol84NetworkAdapter;
use pocketmine\core\component\HealthComponent;
use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\ItemStack;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\component\PositionComponent;
use pocketmine\core\component\RotationComponent;
use pocketmine\core\component\VelocityComponent;
use pocketmine\core\ecs\Entity;
use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\ResourceRegistry;
use pocketmine\core\ecs\World;
use pocketmine\core\resource\ServerConfig;
use pocketmine\core\resource\WorldConfig;
use pocketmine\port\driven\NetworkPort;
use pocketmine\port\driven\PlayerRef;
use pocketmine\protocol\AddEntityPacket;
use pocketmine\protocol\AddItemEntityPacket;
use pocketmine\protocol\AddPlayerPacket;
use pocketmine\protocol\AdventureSettingsPacket;
use pocketmine\protocol\BatchPacket;
use pocketmine\protocol\ChunkRadiusUpdatedPacket;
use pocketmine\protocol\ChunkSerializer;
use pocketmine\protocol\ContainerSetContentPacket;
use pocketmine\protocol\ContainerSetSlotPacket;
use pocketmine\protocol\DropItemPacket;
use pocketmine\protocol\DataPacket;
use pocketmine\protocol\EntityEventPacket;
use pocketmine\protocol\FullChunkDataPacket;
use pocketmine\protocol\Info;
use pocketmine\protocol\InteractPacket;
use pocketmine\protocol\LoginPacket;
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
use pocketmine\protocol\SetHealthPacket;
use pocketmine\protocol\SetSpawnPositionPacket;
use pocketmine\protocol\SetTimePacket;
use pocketmine\protocol\StartGamePacket;
use pocketmine\protocol\TextPacket;
use pocketmine\protocol\UpdateBlockPacket;
use pocketmine\protocol\UpdateAttributesPacket;
use pocketmine\protocol\UseItemPacket;
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
    private readonly BlockBreakService $blockBreakService;
    private readonly BlockPlaceService $blockPlaceService;
    // 14.3: combat routing (attack via InteractPacket) and respawn handling
    // (RespawnPacket after death) reach the services directly.
    private readonly CombatService $combatService;
    private readonly PlayerRespawnService $playerRespawnService;
    private readonly EntityInteractionService $entityInteractionService;
    private readonly EntitySpawnService $entitySpawnService;
    private readonly ResourceRegistry $resourceRegistry;

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
     *   radius: int,
     *   chunkQueue: list<array{0: int, 1: int}>,
     *   chunkQueueIndex: int,
     *   chunksSent: array<string, bool>,
     *   spawned: bool,
     *   lastHealth: float,
     *   knownEntities: array<int, array{0: float, 1: float, 2: float, 3: float}>,
     *   moveCredit: array<string, int>,
     *   breaking: array{x: int, y: int, z: int, startTick: int}|null
     * }>
     */
    private array $sessions = [];
    /** @var array<string, list<DataPacket>> addrKey => packets awaiting this poll's flush */
    private array $outbound = [];

    /**
     * Mob type name -> protocol-84 AddEntityPacket network id. These are the
     * legacy Entity::NETWORK_ID values (authoritative for 0.15.x clients).
     */
    private const MOB_NETWORK_IDS = [
        'Zombie' => 32,
        'Skeleton' => 34,
        'Creeper' => 33,
        'Spider' => 35,
        'Cow' => 11,
        'Pig' => 12,
        'Sheep' => 13,
        'Chicken' => 10,
    ];
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
        ResourceRegistry $resourceRegistry,
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
        $this->resourceRegistry = $resourceRegistry;
        $this->wireTrace = getenv('KHRONOS_WIRE_TRACE') === '1';
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
        // 14.2: mirror live entities to every session (add/move/remove) so
        // other players, mobs and dropped items are visible on the wire.
        $this->broadcastEntityStates();
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
            'radius' => self::DEFAULT_RADIUS,
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
        ];

        // Broadcast the new player to everyone (including themselves) and
        // hand the newcomer the list entries of everyone already online so
        // their skins/names render (they see the players as entities on the
        // next per-tick sync).
        $this->broadcastPlayerListAdd($uuid, $playerRef->entityId, $username, $pk->skin ?? '');
        $this->sendExistingPlayerList($addrKey);
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
        if ($pk->action === PlayerActionPacket::ACTION_START_BREAK) {
            $ticks = $this->blockBreakService->requiredBreakTicks($session['entityRef'], $pk->x, $pk->y, $pk->z);
            if ($ticks < 0) {
                return; // unreachable or unbreakable: nothing starts
            }
            if ($ticks === 0) {
                // Creative or instant-break block (torches, saplings, ...).
                if ($this->blockBreakService->breakBlock($session['entityRef'], $pk->x, $pk->y, $pk->z, $pk->face)) {
                    $this->broadcastBlockState($pk->x, $pk->y, $pk->z);
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
        if ($this->blockBreakService->breakBlock($session['entityRef'], $x, $y, $z, 1)) {
            $this->broadcastBlockState($x, $y, $z);
        }
    }

    private function currentTick(): int {
        $counter = $this->resourceRegistry->get(\pocketmine\core\resource\TickCounter::class);
        return $counter instanceof \pocketmine\core\resource\TickCounter ? $counter->value : 0;
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
        $held = $inventory->get($inventory->heldSlot);
        if ($held === null || $held->count <= 0) {
            return;
        }
        $blockId = $held->itemId;
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
            $this->broadcastBlockState($targetX, $targetY, $targetZ);
            $this->sendInventorySlot($session['playerRef'], $inventory->heldSlot);
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
     * own inventory window (window 0). Protocol 84's player inventory has no
     * separate source/destination fields - the client sends the NEW contents
     * of one slot per packet, so a drag produces several of these.
     *
     * We apply the authoritative state directly (the client is the source of
     * truth for its own window layout) and mirror the change back to the
     * actor so the window stays in lockstep. Only window 0 is wired so far;
     * armor (0x78) and future container windows are ignored.
     */
    private function handleContainerSetSlot(string $addrKey, ContainerSetSlotPacket $pk): void {
        if ($pk->windowid !== ContainerSetContentPacket::SPECIAL_INVENTORY) {
            return; // armor/container windows are not wired yet
        }
        $session = $this->sessions[$addrKey] ?? null;
        if ($session === null) {
            return;
        }
        $inventory = $session['entityRef']->getEntity()?->get(InventoryComponent::class);
        if ($inventory === null || $pk->slot < 0 || $pk->slot >= $inventory->size) {
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

        $current = $inventory->get($pk->slot);
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
            $inventory->set($pk->slot, null);
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
            $inventory->set($pk->slot, new ItemStack($id, $meta, $count));
        }
        $session['moveCredit'] = $credit;
        $this->sessions[$addrKey] = $session;
        // Mirror the authoritative slot back (legacy PlayerInventory::sendSlot).
        $this->sendInventorySlot($session['playerRef'], $pk->slot);
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
            if ($this->entityInteractionService->interact($session['entityRef'], $targetRef)) {
                // 14.5: the picked-up stack must appear in the actor's own
                // inventory window (the walk-over path syncs through the
                // public syncInventoryContents hook instead).
                $this->syncInventoryContents($selfId);
            }
        }
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
            $xpLevel = (int)($meta?->get('xpLevel') ?? 0);
            $xp = (int)($meta?->get('xp') ?? 0);
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
        $move->eid = $playerRef->entityId;
        $move->x = $pos?->x ?? 0.0;
        $move->y = $pos?->y ?? 0.0;
        $move->z = $pos?->z ?? 0.0;
        $move->yaw = 0.0;
        $move->bodyYaw = 0.0;
        $move->pitch = 0.0;
        $move->mode = MovePlayerPacket::MODE_RESET;
        $move->onGround = true;
        $this->queuePacket($playerRef, $move);

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
    private function broadcastBlockState(int $x, int $y, int $z): void {
        $store = $this->world->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);
        if (!$store instanceof \pocketmine\core\resource\ChunkStore) {
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
            $this->queuePacket($s['playerRef'], clone $pk);
        }
    }

    /** Send one inventory slot (window 0 = the player's own inventory). */
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
        $pk->windowid = ContainerSetContentPacket::SPECIAL_INVENTORY;
        $pk->slot = $slot;
        // For the player's own window the hotbar mapping is the identity for
        // hotbar slots 0-8 (all current callers use held hotbar slots); main
        // inventory slots (9+) would need the real hotbar mapping.
        $pk->hotbarSlot = $slot;
        $pk->item = $item !== null ? [$item->itemId, $item->count, $item->meta, $item->nbt] : [0, 0, 0, null];
        $this->queuePacket($player, $pk);
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
        for ($i = 0; $i < $inventory->size; $i++) {
            $item = $inventory->get($i);
            $pk->slots[] = $item !== null ? [$item->itemId, $item->count, $item->meta, $item->nbt] : [0, 0, 0, null];
        }
        $this->queuePacket($player, $pk);
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
        $worldConfig = $this->resourceRegistry->get(WorldConfig::class);
        $time->time = $worldConfig instanceof WorldConfig ? $worldConfig->time : 0;
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

        // Full inventory contents (window 0) so the client renders the
        // hotbar with the player's actual items (starter kit for new players).
        $this->sendInventoryContents($playerRef);

        // 14.9: init the XP bar (level 0, empty progress).
        $this->syncXpFor($session['playerRef']->entityId);
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
        $worldConfig = $this->resourceRegistry->get(WorldConfig::class);
        $time = $worldConfig instanceof WorldConfig ? $worldConfig->time : 0;
        $pk = new SetTimePacket();
        $pk->time = $time;
        $pk->started = true;
        foreach ($this->sessions as $session) {
            $this->queuePacket($session['playerRef'], clone $pk);
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
            foreach ($worldEntities as $entityId => $entity) {
                if ($entityId === $selfId) {
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
        $item = $meta?->get('item');
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
        if ($meta?->get('xp') !== null && $entity->has('xp_orb')) {
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
        $type = $meta?->get('mobType') ?? $meta?->get('entityType');
        $networkId = self::MOB_NETWORK_IDS[$type] ?? null;
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
        $pk->metadata[2] = [\pocketmine\utils\Binary::DATA_TYPE_STRING, (string)$type];
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
                $this->sendChunkBatch($addrKey, $chunk);

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

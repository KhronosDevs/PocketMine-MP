<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';
require_once __DIR__ . '/helpers.php';

use pocketmine\adapter\driven\network\Protocol84NetworkAdapter;
use pocketmine\protocol\AnimatePacket;
use pocketmine\protocol\BatchPacket;
use pocketmine\protocol\ContainerClosePacket;
use pocketmine\protocol\ContainerSetContentPacket;
use pocketmine\protocol\ContainerSetSlotPacket;
use pocketmine\protocol\CraftingEventPacket;
use pocketmine\protocol\DropItemPacket;
use pocketmine\protocol\FullChunkDataPacket;
use pocketmine\protocol\Info;
use pocketmine\protocol\LoginPacket;
use pocketmine\protocol\MobEquipmentPacket;
use pocketmine\protocol\MovePlayerPacket;
use pocketmine\protocol\PlayStatusPacket;
use pocketmine\protocol\PlayerActionPacket;
use pocketmine\protocol\RemoveBlockPacket;
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

require_once __DIR__ . '/fake_client.php'; // defines FakeClient (shared with the feature tests)
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

/**
 * CraftingDataPacket (0x2f): entry count, then per entry [type, len, payload]
 * where shaped payloads are width, height, width*height slots, result count,
 * result slot, uuid. Only shaped (type 1) entries are decoded.
 */
function cdFields(string $buf): array {
    $s = new BinaryStream($buf, 1);
    $count = $s->getInt();
    $recipes = [];
    for ($i = 0; $i < $count && $i < 128; $i++) {
        $type = $s->getInt();
        $len = $s->getInt();
        $payload = substr($s->getBuffer(), $s->getOffset(), $len);
        $s->offset += $len;
        if ($type !== 1) {
            continue;
        }
        $r = new BinaryStream($payload);
        $width = $r->getInt();
        $height = $r->getInt();
        $ingredients = [];
        for ($j = 0; $j < $width * $height; $j++) {
            $ingredients[] = $r->getSlot();
        }
        $resultCount = $r->getInt();
        $result = $r->getSlot();
        $r->getUUID(); // recipe uuid
        $recipes[] = [
            'width' => $width,
            'height' => $height,
            'ingredients' => $ingredients,
            'resultCount' => $resultCount,
            'result' => $result,
        ];
    }
    return ['count' => $count, 'recipes' => $recipes];
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

/**
 * LevelEventPacket (0x1a): evid short, x/y/z floats, data int.
 */
function leFields(string $buf): array {
    $s = new BinaryStream($buf, 1);
    return [
        'evid' => $s->getShort(),
        'x' => $s->getFloat(),
        'y' => $s->getFloat(),
        'z' => $s->getFloat(),
        'data' => $s->getInt(),
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

function uaFields(string $buf): array {
    $s = new BinaryStream($buf, 1);
    $eid = $s->getLong();
    $count = $s->getShort();
    $entries = [];
    for ($i = 0; $i < $count; $i++) {
        $min = $s->getFloat();
        $max = $s->getFloat();
        $value = $s->getFloat();
        $name = $s->getString();
        $entries[$name] = ['min' => $min, 'max' => $max, 'value' => $value];
    }
    return ['eid' => $eid, 'entries' => $entries];
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
// The kernel's dataPath is the process cwd (the repo in standalone runs, a
// per-file temp dir under tests/run.php -j), so clean the worlds dir next to
// the cwd - not the repo's tests dir.
$worldsDir = getcwd() . '/worlds';
if (is_dir($worldsDir)) {
    exec('rm -rf ' . escapeshellarg($worldsDir));
}

$port = 20000 + random_int(0, 20000);
$kernel = \pocketmine\bootstrap();
$kernel->setNetworkingEnabled(true);
$kernel->setBindPort($port);
$kernel->setAutoShutdownOnRun(false);

// Pin the world seed: with a fresh world the seed is first consumed at the
// first chunk generation, so setting it right after bootstrap makes the
// terrain fully deterministic. A random seed occasionally spawned the
// configured spawn inside an ocean, which moved findSafeSpawn's result far
// enough to break the 'spawn stays near the configured world spawn' bound.
// seed=1 gives dry land exactly at the configured (0,0) spawn (verified).
$serverCfg = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ServerConfig::class);
if ($serverCfg instanceof \pocketmine\core\resource\ServerConfig) {
    $serverCfg->seed = 1;
}

// 14.3: the builtin MobSpawnerSystem would otherwise populate hostile mobs
// around Alice every 40 ticks, making her health/position non-deterministic
// for the assertions below (the mob spawner has its own dedicated test).
$worldCfg = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ServerConfig::class);
if ($worldCfg instanceof \pocketmine\core\resource\ServerConfig) {
    $worldCfg->spawnMobs = false;
}

// The command wire tests below send back-to-back chat commands; the default
// anti-cheat chat (0.4s) and command (0.1s) rate limits silently drop a
// second command that arrives sooner. The loops here are fast (run(1) no
// longer pays a per-call autosave), so commands can process within those
// windows - disable the intervals for this harness (the limits themselves
// have their own coverage; see the anti-cheat test further down).
$khronosCfg = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\KhronosConfig::class);
if ($khronosCfg instanceof \pocketmine\core\resource\KhronosConfig) {
    $khronosCfg->chatMinIntervalSeconds = 0.0;
    $khronosCfg->commandMinIntervalSeconds = 0.0;
}

// Blocker 1: admin commands are gated behind op, so grant Alice op before she
// joins - the command wire tests below (/gamemode /give /tp /time /weather
// /kill) exercise the happy path (the gating itself has its own test in
// tests/30).
$lists = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\PlayerListManager::class);
if ($lists instanceof \pocketmine\core\resource\PlayerListManager) {
    $lists->addOp('alice');
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

    // Gather packets until the burst essentials are all seen (12 distinct
    // ids: inventory content, recipe list, adventure settings from
    // doFirstSpawn after PLAYER_SPAWN).
    $deadline = microtime(true) + 8.0;
    $seen = [];
    $sawAdventure = false;
    while (microtime(true) < $deadline && !($sawSpawn && $sawAdventure)) {
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            $seen[$id] = true;
            $loginPackets[] = [$id, $buffer];
            if ($id === Info::PLAY_STATUS_PACKET && psStatus($buffer) === PlayStatusPacket::PLAYER_SPAWN) {
                $sawSpawn = true;
            }
            if ($id === Info::ADVENTURE_SETTINGS_PACKET) {
                $sawAdventure = true;
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

    // 14.1: the full inventory (window 0) is sent in doFirstSpawn (after
    // PLAYER_SPAWN), not in the login handler. Find the window-0 packet
    // among all ContainerSetContentPackets.
    $inventoryContent = null;
    foreach ($loginPackets as [$pid, $pbuf]) {
        if ($pid === Info::CONTAINER_SET_CONTENT_PACKET) {
            $tmp = cscFields($pbuf);
            if ($tmp['windowid'] === 0) {
                $inventoryContent = $tmp;
                break;
            }
        }
    }
    ok($inventoryContent !== null, 'inventory content (window 0) sent on login');
    same(45, count($inventoryContent['slots']), '45 inventory slots (36 real + 9 dummy hotbar)');
    // Starter kit: planks in slot 0 (held), cobblestone in slot 1.
    same(5, $inventoryContent['slots'][0][0], 'slot 0 holds planks');
    same(32, $inventoryContent['slots'][0][1], '32 planks in slot 0');
    same(4, $inventoryContent['slots'][1][0], 'slot 1 holds cobblestone');

    // 14.12: the login burst carries the recipe list so the client can
    // render the crafting UI.
    ok(isset($byId[Info::CRAFTING_DATA_PACKET]), 'crafting data sent on login');
    $cd = cdFields($byId[Info::CRAFTING_DATA_PACKET]);
    same(9, $cd['count'], 'nine shaped recipes');
    // A 1x1 'L' -> 4 planks recipe is the first registered one; its ingredient
    // uses the wildcard damage marker (0x7fff) for any log wood type.
    $first = $cd['recipes'][0] ?? null;
    ok($first !== null, 'first recipe decodes');
    if ($first !== null) {
        same(1, $first['width'], 'planks recipe is 1 wide');
        same(1, $first['height'], 'planks recipe is 1 tall');
        same(5, $first['result'][0], 'planks recipe yields planks');
        same(4, $first['result'][1], 'four planks per log');
        same(32767, $first['ingredients'][0][2], 'log ingredient uses wildcard damage');
    }
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
    // One small walking step (well under the anti-cheat caps) - the same
    // single-round-trip shape as the original test, which the slow-tick test
    // environment tolerates (multi-step walks time the RakLib thread out).
    $before = null;
    foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
        if ($p['username'] === 'Alice') {
            $before = $p;
        }
    }
    if ($before === null) {
        ok(false, 'Alice is online');
        return;
    }
    $tx = $before['x'] + 0.5;
    $ty = $before['y'];
    $tz = $before['z'] - 0.5;
    $move = new MovePlayerPacket();
    $move->eid = 0;
    $move->x = $tx;
    $move->y = $ty;
    $move->z = $tz;
    $move->yaw = 90.0;
    $move->bodyYaw = 90.0;
    $move->pitch = 10.0;
    $move->mode = MovePlayerPacket::MODE_NORMAL;
    $move->onGround = true;
    $client->sendGamePacket($move);

    // The packet travels client socket -> RakLib thread -> kernel, so poll
    // until the position lands.
    $deadline = microtime(true) + 6.0;
    while (microtime(true) < $deadline) {
        $client->readGamePackets();
        $kernel->run(1);
        foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
            if ($p['username'] === 'Alice' && abs($p['x'] - $tx) < 1e-6) {
                near($tx, $p['x'], 1e-6, 'x applied');
                near($ty, $p['y'], 1e-6, 'y applied');
                near($tz, $p['z'], 1e-6, 'z applied');
                return;
            }
        }
        usleep(10000);
    }
    ok(false, 'movement applied to the ECS entity');
});

// --- Blocker 2 anti-cheat ------------------------------------------------
test('an overspeed move is rejected and the client is rubber-banded', function () use ($client, $kernel): void {
    // Alice's position before the hack attempt.
    $before = null;
    foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
        if ($p['username'] === 'Alice') {
            $before = $p;
        }
    }
    if ($before === null) {
        ok(false, 'Alice is online');
        return;
    }

    // A teleport hack: 100 blocks in one packet (4000+ blocks/sec).
    $hack = new MovePlayerPacket();
    $hack->eid = 0;
    $hack->x = $before['x'] + 100.0;
    $hack->y = $before['y'];
    $hack->z = $before['z'];
    $hack->yaw = 0.0;
    $hack->bodyYaw = 0.0;
    $hack->pitch = 0.0;
    $hack->mode = MovePlayerPacket::MODE_NORMAL;
    $hack->onGround = true;
    $client->sendGamePacket($hack);

    // The server must NOT apply the move, and must snap the client back with
    // a rubber-band MovePlayerPacket (eid 0 = self, MODE_RESET).
    $deadline = microtime(true) + 4.0;
    $sawRubberBand = false;
    while (microtime(true) < $deadline) {
        $kernel->run(1);
        foreach ($client->readGamePackets() as [$id, $buffer]) { // drains + ACKs
            if ($id === Info::MOVE_PLAYER_PACKET) {
                $mp = mpFields($buffer);
                if ($mp['eid'] === 0 && $mp['mode'] === MovePlayerPacket::MODE_RESET) {
                    $sawRubberBand = true;
                }
            }
        }
        foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
            if ($p['username'] === 'Alice') {
                near($before['x'], $p['x'], 1e-6, 'rejected move never applied (x)');
                near($before['z'], $p['z'], 1e-6, 'rejected move never applied (z)');
                break;
            }
        }
        if ($sawRubberBand) {
            break;
        }
        usleep(10000);
    }
    ok($sawRubberBand, 'client was rubber-banded to the authoritative position');
});

test('repeated overspeed moves end in a kick', function () use ($kernel, $port): void {
    // A dedicated cheater so the kick does not disturb Alice's session.
    $cheater = new FakeClient($port);
    $cheater->handshake(fn() => $kernel->run(1));
    $cheater->connect(fn() => $kernel->run(1));
    $cheater->sendLogin('Cheater', 'eeeeeeee-dddd-cccc-bbbb-aaaaaaaaaaaa');
    $deadline = microtime(true) + 6.0;
    while (microtime(true) < $deadline) {
        $kernel->run(1);
        $online = false;
        foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
            if ($p['username'] === 'Cheater') {
                $online = true;
            }
        }
        if ($online) {
            break;
        }
        usleep(10000);
    }

    $pos = null;
    foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
        if ($p['username'] === 'Cheater') {
            $pos = $p;
        }
    }
    if ($pos === null) {
        ok(false, 'Cheater joined');
        $cheater->close();
        return;
    }

    // 5 instant teleports (way over MAX_MOVE_VIOLATIONS) back-to-back.
    $deadline = microtime(true) + 6.0;
    $kicked = false;
    while (microtime(true) < $deadline && !$kicked) {
        $hack = new MovePlayerPacket();
        $hack->eid = 0;
        $hack->x = $pos['x'] + 50.0;
        $hack->y = $pos['y'];
        $hack->z = $pos['z'] + 50.0;
        $hack->yaw = 0.0;
        $hack->bodyYaw = 0.0;
        $hack->pitch = 0.0;
        $hack->mode = MovePlayerPacket::MODE_NORMAL;
        $hack->onGround = true;
        $cheater->sendGamePacket($hack);
        $cheater->readGamePackets();
        $kernel->run(1);
        // Drain the server's rubber-band frames too (they ride reliable
        // channels the ACK window must keep clear).
        $cheater->readGamePackets();
        $kicked = true;
        foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
            if ($p['username'] === 'Cheater') {
                $kicked = false;
            }
        }
        usleep(20000);
    }
    ok($kicked, 'repeat overspeed violator was kicked');
    $cheater->close();
});

test('a spam flood of chat is throttled to one message', function () use ($client, $kernel): void {
    // This test NEEDS the rate limit, so it sets its own interval (the
    // harness elsewhere disables it for the back-to-back command tests).
    $khronosCfg = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\KhronosConfig::class);
    $prev = $khronosCfg instanceof \pocketmine\core\resource\KhronosConfig ? $khronosCfg->chatMinIntervalSeconds : 0.4;
    if ($khronosCfg instanceof \pocketmine\core\resource\KhronosConfig) {
        $khronosCfg->chatMinIntervalSeconds = 0.4;
    }
    try {
        // Send 10 messages instantly - only the first (within the 400ms window)
        // should be echoed; the rest are dropped as spam.
        for ($i = 0; $i < 10; $i++) {
            $chat = new TextPacket();
            $chat->type = TextPacket::TYPE_CHAT;
            $chat->source = 'Alice';
            $chat->message = "spam message $i";
            $client->sendGamePacket($chat);
        }
        $kernel->run(3);

        $deadline = microtime(true) + 3.0;
        $echoes = 0;
        while (microtime(true) < $deadline) {
            foreach ($client->readGamePackets() as [$id, $buffer]) {
                if ($id === Info::TEXT_PACKET) {
                    $tp = textPacket($buffer);
                    if ($tp['type'] === TextPacket::TYPE_RAW && str_starts_with($tp['message'], 'Alice: spam message')) {
                        $echoes++;
                    }
                }
            }
            $kernel->run(1);
            if ($echoes >= 2) {
                break; // over the limit - fail fast
            }
            usleep(10000);
        }
        ok($echoes === 1, "spam throttled: 1 echo for 10 messages (got $echoes)");
    } finally {
        if ($khronosCfg instanceof \pocketmine\core\resource\KhronosConfig) {
            $khronosCfg->chatMinIntervalSeconds = $prev;
        }
    }
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
    $id = null;
    foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
        if ($p['username'] === 'Alice') {
            $id = $p['entityId'];
            break;
        }
    }
    if ($id === null) {
        throw new RuntimeException('Alice is not online');
    }
    $kernel->getNetworkSessionService()->sendTeleportTo($id, $x + 0.5, $y + 1, $z + 0.5);

    $deadline = microtime(true) + 3.0;
    while (microtime(true) < $deadline) {
        $kernel->run(1);
        foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
            // Target Alice by name: after the second-player test Bob is also
            // online, and the first list entry is not guaranteed to be Alice.
            if ($p['username'] === 'Alice' && abs($p['x'] - ($x + 0.5)) < 1e-6) {
                return;
            }
        }
        usleep(10000);
    }
    throw new RuntimeException('teleport to (' . $x . ', ' . $y . ', ' . $z . ') did not land');
}

/** Teleport a specific entity (by id) onto a block and wait for it to land. */
function teleportEntityOnto(\pocketmine\Kernel $kernel, FakeClient $client, int $entityId, int $x, int $y, int $z): void {
    $kernel->getNetworkSessionService()->sendTeleportTo($entityId, $x + 0.5, $y + 1, $z + 0.5);

    $deadline = microtime(true) + 3.0;
    while (microtime(true) < $deadline) {
        $kernel->run(1);
        foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
            if ($p['entityId'] === $entityId && abs($p['x'] - ($x + 0.5)) < 1e-6) {
                return;
            }
        }
        usleep(10000);
    }
    throw new RuntimeException('teleport entity ' . $entityId . ' to (' . $x . ', ' . $y . ', ' . $z . ') did not land');
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

test('PlayerItemHeldEvent fires on MobEquipment and cancellation rejects the slot change', function () use ($client, $kernel): void {
    $port = $kernel->getEventPort();
    $fired = 0;
    $handler = function (\pocketmine\api\event\PlayerItemHeldEvent $e) use (&$fired): void {
        if ($e->getPlayer()->getName() === 'Alice') {
            $fired++;
            $e->setCancelled(true);
        }
    };
    $port->subscribe(\pocketmine\api\event\PlayerItemHeldEvent::class, $handler);
    try {
        // Alice tries to select hotbar slot 2 - the plugin vetoes it.
        $me = new MobEquipmentPacket();
        $me->eid = 0;
        $me->item = [4, 32, 0, null];
        $me->slot = 2;
        $me->selectedSlot = 2;
        $client->sendGamePacket($me);
        // Wait for the packet to be processed (the event fires) - then check
        // the held slot was NOT changed to 2 by the cancellation.
        $deadline = microtime(true) + 3.0;
        $rejected = false;
        while (microtime(true) < $deadline && $fired === 0) {
            $kernel->run(1);
            usleep(10000);
        }
        // Give the flush a moment, then confirm the slot never moved to 2.
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            $kernel->run(1);
            $online = $kernel->getNetworkSessionService()->getOnlinePlayers();
            if (isset($online[0])) {
                $inv = $kernel->getWorld()->getEntity($online[0]['entityId'])?->get(\pocketmine\core\component\InventoryComponent::class);
                if ($inv !== null && $inv->heldSlot === 1) {
                    $rejected = true;
                    break;
                }
            }
            usleep(10000);
        }
        ok($fired >= 1, 'PlayerItemHeldEvent fired');
        ok($rejected, 'cancelled PlayerItemHeldEvent keeps the held slot');
    } finally {
        $port->unsubscribe(\pocketmine\api\event\PlayerItemHeldEvent::class, $handler);
    }
});

test('PlayerAnimationEvent fires on AnimatePacket and broadcasts to other players', function () use ($client, $kernel): void {
    $port = $kernel->getEventPort();
    $fired = 0;
    $port->subscribe(\pocketmine\api\event\PlayerAnimationEvent::class, function (\pocketmine\api\event\PlayerAnimationEvent $e) use (&$fired): void {
        if ($e->getPlayer()->getName() === 'Alice') {
            $fired++;
            same(\pocketmine\api\event\PlayerAnimationEvent::ARM_SWING, $e->getAnimationType(), 'arm swing type');
        }
    });
    $anim = new AnimatePacket();
    $anim->action = 1; // ARM_SWING
    $anim->eid = 0;
    $client->sendGamePacket($anim);
    $deadline = microtime(true) + 3.0;
    while (microtime(true) < $deadline && $fired === 0) {
        $kernel->run(1);
        usleep(10000);
    }
    ok($fired >= 1, 'PlayerAnimationEvent fired for the arm swing');
});

test('InventoryTransactionEvent fires on ContainerSetSlot and cancellation rejects the move', function () use ($client, $kernel): void {
    $port = $kernel->getEventPort();
    $fired = 0;
    $handler = function (\pocketmine\api\event\InventoryTransactionEvent $e) use (&$fired): void {
        if ($e->getPlayer()->getName() === 'Alice') {
            $fired++;
            $e->setCancelled(true);
        }
    };
    $port->subscribe(\pocketmine\api\event\InventoryTransactionEvent::class, $handler);
    try {
        // Give Alice a real item in slot 5 so the cancelled 'empty the slot'
        // transaction has something to preserve.
        $online0 = $kernel->getNetworkSessionService()->getOnlinePlayers();
        $inv0 = null;
        if (isset($online0[0])) {
            $inv0 = $kernel->getWorld()->getEntity($online0[0]['entityId'])?->get(\pocketmine\core\component\InventoryComponent::class);
        }
        ok($inv0 !== null, 'Alice inventory present');
        if ($inv0 !== null) {
            $inv0->set(5, new \pocketmine\core\component\ItemStack(4, 0, 3)); // 3 cobblestone
        }
        $beforeKey = '4:0:3';

        // Alice reports emptying slot 5 - the plugin rejects the transaction,
        // so the authoritative inventory must keep the item.
        $css = new ContainerSetSlotPacket();
        $css->windowid = ContainerSetContentPacket::SPECIAL_INVENTORY;
        $css->slot = 5;
        $css->hotbarSlot = 0;
        $css->item = [0, 0, 0, null];
        $client->sendGamePacket($css);
        // Wait for the packet to be processed (the transaction event fires),
        // THEN verify the slot was not mutated by the cancellation.
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline && $fired === 0) {
            $kernel->run(1);
            usleep(10000);
        }
        $deadline = microtime(true) + 3.0;
        $unchanged = false;
        while (microtime(true) < $deadline) {
            $kernel->run(1);
            $online = $kernel->getNetworkSessionService()->getOnlinePlayers();
            if (isset($online[0])) {
                $inv = $kernel->getWorld()->getEntity($online[0]['entityId'])?->get(\pocketmine\core\component\InventoryComponent::class);
                if ($inv !== null) {
                    $now = $inv->get(5);
                    $nowKey = $now !== null ? $now->itemId . ':' . $now->meta . ':' . $now->count : 'empty';
                    if ($nowKey === $beforeKey) {
                        $unchanged = true;
                        break;
                    }
                }
            }
            usleep(10000);
        }
        ok($fired >= 1, 'InventoryTransactionEvent fired');
        ok($unchanged, 'cancelled transaction keeps the authoritative slot');
    } finally {
        $port->unsubscribe(\pocketmine\api\event\InventoryTransactionEvent::class, $handler);
    }
});

test('DataPacketReceiveEvent and DataPacketSendEvent fire on the wire path', function () use ($client, $kernel): void {
    $port = $kernel->getEventPort();
    $recv = 0;
    $send = 0;
    $recvHandler = function (\pocketmine\api\event\DataPacketReceiveEvent $e) use (&$recv): void {
        if ($e->getPlayer()->getName() === 'Alice') {
            $recv++;
        }
    };
    $sendHandler = function (\pocketmine\api\event\DataPacketSendEvent $e) use (&$send): void {
        if ($e->getPlayer()->getName() === 'Alice') {
            $send++;
        }
    };
    $port->subscribe(\pocketmine\api\event\DataPacketReceiveEvent::class, $recvHandler);
    $port->subscribe(\pocketmine\api\event\DataPacketSendEvent::class, $sendHandler);
    // Alice sends a chat packet; the receive event fires. The response echo
    // fires the send event.
    $txt = new \pocketmine\protocol\TextPacket();
    $txt->type = 1; // raw chat
    $txt->message = 'hello events';
    $client->sendGamePacket($txt);
    $deadline = microtime(true) + 3.0;
    while (microtime(true) < $deadline && ($recv === 0 || $send === 0)) {
        $kernel->run(1);
        usleep(10000);
    }
    ok($recv >= 1, 'DataPacketReceiveEvent fired');
    ok($send >= 1, 'DataPacketSendEvent fired');
    $port->unsubscribe(\pocketmine\api\event\DataPacketReceiveEvent::class, $recvHandler);
    $port->unsubscribe(\pocketmine\api\event\DataPacketSendEvent::class, $sendHandler);
});

test('breaking a block requires holding and then confirms via RemoveBlockPacket', function () use ($client, $kernel): void {
    [$bx, $by, $bz] = findSurfaceBlockNearSpawn($kernel);
    teleportAliceOnto($kernel, $client, $bx, $by, $bz);

    // Phase 1: press and hold (ACTION_START_BREAK) - the server records the
    // break start and the client animates the crack locally.
    $action = new PlayerActionPacket();
    $action->eid = 0;
    $action->action = PlayerActionPacket::ACTION_START_BREAK;
    $action->x = $bx;
    $action->y = $by;
    $action->z = $bz;
    $action->face = 1;
    $client->sendGamePacket($action);
    $kernel->run(1); // the START is processed on this tick

    // Phase 2: hold the button for the server-side requirement (grass/dirt
    // needs ~12 ticks), then the client's local crack timer finishes and it
    // confirms with REMOVE_BLOCK_PACKET. Drain the client every tick so ACKs
    // flow and the RakLib session never times out (a real client ACKs
    // continuously; a silent hold loop starves the session on slow ticks).
    for ($i = 0; $i < 20; $i++) {
        $kernel->run(1);
        $client->readGamePackets();
    }
    $rm = new RemoveBlockPacket();
    $rm->eid = 0;
    $rm->x = $bx;
    $rm->y = $by;
    $rm->z = $bz;
    $client->sendGamePacket($rm);

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

test('an instant RemoveBlock confirm is rejected (no insta-mining)', function () use ($client, $kernel): void {
    [$bx, $by, $bz] = findSurfaceBlockNearSpawn($kernel);
    teleportAliceOnto($kernel, $client, $bx, $by, $bz);
    $store = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);
    $store = $store instanceof \pocketmine\core\resource\ChunkStore ? $store : null;
    if ($store === null) {
        ok(false, 'chunk store present');
        return;
    }
    $before = $store->getBlock($bx, $by, $bz);
    ok($before !== 0, 'target block is solid before the attack');

    // START and IMMEDIATE confirm in the same tick: a hacked client that
    // never held the button. The server must reject it.
    $action = new PlayerActionPacket();
    $action->eid = 0;
    $action->action = PlayerActionPacket::ACTION_START_BREAK;
    $action->x = $bx;
    $action->y = $by;
    $action->z = $bz;
    $action->face = 1;
    $client->sendGamePacket($action);
    $rm = new RemoveBlockPacket();
    $rm->eid = 0;
    $rm->x = $bx;
    $rm->y = $by;
    $rm->z = $bz;
    $client->sendGamePacket($rm);
    $kernel->run(3); // START + instant confirm + grace

    ok($store->getBlock($bx, $by, $bz) === $before, 'block survives an instant confirm');
});

test('creative mode breaks instantly on REMOVE_BLOCK alone (no crack, no START_BREAK)', function () use ($client, $kernel): void {
    // The 0.15 creative client never sends ACTION_START_BREAK: blocks break
    // instantly with no crack animation, so only REMOVE_BLOCK arrives. The
    // server must honour it without an in-progress break.
    $eid = null;
    foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
        if ($p['username'] === 'Alice') {
            $eid = $p['entityId'];
            break;
        }
    }
    ok($eid !== null, 'Alice online');
    $entity = $kernel->getWorld()->getEntity($eid);
    $meta = $entity?->get(\pocketmine\core\component\MetadataComponent::class);
    if ($meta !== null) {
        $meta->set('gamemode', 1); // creative
    }

    [$bx, $by, $bz] = findSurfaceBlockNearSpawn($kernel);
    teleportAliceOnto($kernel, $client, $bx, $by, $bz);
    $store = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);
    $store = $store instanceof \pocketmine\core\resource\ChunkStore ? $store : null;
    ok($store !== null && $store->getBlock($bx, $by, $bz) !== 0, 'target block is solid before break');

    // Only REMOVE_BLOCK - no prior START_BREAK, no hold.
    $rm = new RemoveBlockPacket();
    $rm->eid = 0;
    $rm->x = $bx;
    $rm->y = $by;
    $rm->z = $bz;
    $client->sendGamePacket($rm);

    $deadline = microtime(true) + 3.0;
    while (microtime(true) < $deadline && ($store === null || $store->getBlock($bx, $by, $bz) !== 0)) {
        $kernel->run(1);
        $client->readGamePackets();
        usleep(10000);
    }
    ok($store !== null && $store->getBlock($bx, $by, $bz) === 0, 'creative REMOVE_BLOCK breaks the block');

    // Back to survival for the rest of the suite.
    if ($meta !== null) {
        $meta->set('gamemode', 0);
    }
});

test('creative reach is 13 blocks, survival reach is 6 blocks (legacy parity)', function () use ($client, $kernel): void {
    // Legacy: canInteract($vec, $this->isCreative() ? 13 : 6). Blocks beyond
    // reach silently fail (the client resends and the block respawns).
    $eid = null;
    foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
        if ($p['username'] === 'Alice') { $eid = $p['entityId']; break; }
    }
    ok($eid !== null, 'Alice online');
    $entity = $kernel->getWorld()->getEntity($eid);
    $meta = $entity?->get(\pocketmine\core\component\MetadataComponent::class);
    $store = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);
    $store = $store instanceof \pocketmine\core\resource\ChunkStore ? $store : null;
    ok($store !== null, 'chunk store present');
    if ($store === null || $meta === null) return;

    [$sx, $sy, $sz] = findSurfaceBlockNearSpawn($kernel);
    $ref = \pocketmine\core\ecs\EntityRef::create($eid, $kernel->getWorld());

    // --- Part A: Creative reach (13 blocks) ---
    $meta->set('gamemode', 1);
    // Place a block 10 blocks away; player teleported 3 blocks from spawn
    // (~7 blocks from target, within creative reach of 13).
    $tx = $sx + 10; $ty = $sy; $tz = $sz;
    $store->setBlock($tx, $ty, $tz, 1);
    teleportAliceOnto($kernel, $client, $sx + 3, $sy, $sz);
    ok($store->getBlock($tx, $ty, $tz) !== 0, 'target block solid before creative break');
    ok($kernel->getBlockBreakService()->breakBlock($ref, $tx, $ty, $tz, 1), 'creative break at 7 blocks succeeds');
    ok($store->getBlock($tx, $ty, $tz) === 0, 'block broken in creative');

    // --- Part B: Survival reach (6 blocks) ---
    $store->setBlock($tx, $ty, $tz, 1);
    $meta->set('gamemode', 0);
    // Teleport close to target (2 blocks away, within survival reach of 6).
    teleportAliceOnto($kernel, $client, $sx + 8, $sy, $sz);
    ok($kernel->getBlockBreakService()->breakBlock($ref, $tx, $ty, $tz, 1), 'survival break at 2 blocks succeeds');
    ok($store->getBlock($tx, $ty, $tz) === 0, 'block broken in survival');

    // --- Part C: Survival rejection at long distance ---
    $store->setBlock($tx, $ty, $tz, 1);
    $farTx = $sx + 20;
    $store->setBlock($farTx, $ty, $tz, 1);
    ok(!$kernel->getBlockBreakService()->breakBlock($ref, $farTx, $ty, $tz, 1), 'survival rejects a block 18 blocks away (beyond reach 6)');
    ok($store->getBlock($farTx, $ty, $tz) === 1, 'the far survival block survived');

    // Restore blocks for following tests
    $store->setBlock($tx, $ty, $tz, 1);
    $store->setBlock($farTx, $ty, $tz, 0);
});

test('survival break drops the item with visible metadata (grass drops dirt)', function () use ($client, $kernel): void {
    [$bx, $by, $bz] = findSurfaceBlockNearSpawn($kernel);
    teleportAliceOnto($kernel, $client, $bx, $by, $bz);
    $store = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);
    $store = $store instanceof \pocketmine\core\resource\ChunkStore ? $store : null;
    ok($store !== null, 'chunk store present');
    if ($store === null) {
        return;
    }
    $blockId = $store->getBlock($bx, $by, $bz);
    ok($blockId !== 0, 'target block solid before break');

    // Break via the service directly (the wire path is covered by the break
    // tests): survival break must spawn a visible, pickup-able drop.
    $eid = null;
    foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
        if ($p['username'] === 'Alice') {
            $eid = $p['entityId'];
            break;
        }
    }
    ok($eid !== null, 'Alice online');
    $entity = $kernel->getWorld()->getEntity($eid);
    $entity?->get(\pocketmine\core\component\MetadataComponent::class)?->set('gamemode', 0);

    $kernel->getBlockBreakService()->breakBlock(
        \pocketmine\core\ecs\EntityRef::create($eid, $kernel->getWorld()),
        $bx, $by, $bz, 1,
    );

    // The expected drop: grass (2) -> dirt (3), otherwise the block itself.
    $expected = $blockId === 2 ? 3 : $blockId;
    $dropped = null;
    foreach ($kernel->getWorld()->query()->with(\pocketmine\core\component\MetadataComponent::class)->build() as $e) {
        $m = $e->get(\pocketmine\core\component\MetadataComponent::class);
        if ($m !== null && $m->get('entityType') === 'item') {
            $item = $m->get(\pocketmine\core\constants\MetadataKeys::ITEM);
            if ($item instanceof \pocketmine\core\component\ItemStack && $item->itemId === $expected) {
                $pos = $e->get(\pocketmine\core\component\PositionComponent::class);
                if ($pos !== null && abs($pos->x - $bx - 0.5) < 2 && abs($pos->z - $bz - 0.5) < 2) {
                    $dropped = $item;
                    break;
                }
            }
        }
    }
    ok($dropped instanceof \pocketmine\core\component\ItemStack, 'drop entity carries its item in metadata');
    ok($store->getBlock($bx, $by, $bz) === 0, 'broken block is air in the world');
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

test('placing a torch re-sends the chunk with block light (client sees it light up)', function () use ($client, $kernel): void {
    // Alice selects hotbar slot 3 (torches from the starter kit).
    $me = new MobEquipmentPacket();
    $me->eid = 0;
    $me->item = [50, 16, 0, null];
    $me->slot = 3;
    $me->selectedSlot = 3;
    $client->sendGamePacket($me);
    $kernel->run(1);

    [$bx, $by, $bz] = findSurfaceBlockNearSpawn($kernel);
    teleportAliceOnto($kernel, $client, $bx, $by, $bz);

    // Place the torch into the air cell above the surface block (click the
    // surface block's top face, face 1 = up).
    $use = new UseItemPacket();
    $use->x = $bx;
    $use->y = $by;
    $use->z = $bz;
    $use->face = 1; // up
    $use->fx = 0.5;
    $use->fy = 1.0;
    $use->fz = 0.5;
    $use->posX = $bx + 0.5;
    $use->posY = $by + 1.0;
    $use->posZ = $bz + 0.5;
    $use->slot = 3;
    $use->item = [50, 16, 0, null];
    $client->sendGamePacket($use);

    $store = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);
    $store = $store instanceof \pocketmine\core\resource\ChunkStore ? $store : null;

    $deadline = microtime(true) + 4.0;
    $sawLitChunk = false;
    while (microtime(true) < $deadline && !$sawLitChunk) {
        $kernel->run(1);
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id !== Info::FULL_CHUNK_DATA_PACKET) {
                continue;
            }
            $fc = fcFields($buffer);
            // The torch chunk is the one containing the surface block. Its
            // block-light plane (after 8*4096 ids + 8*2048 data + 8*2048 sky)
            // must have a lit nibble near the torch after the re-send.
            if ($fc['x'] === intdiv($bx, 16) && $fc['z'] === intdiv($bz, 16)) {
                $payload = $fc['data'];
                $blockLightBase = 8 * 4096 + 8 * 2048 + 8 * 2048;
                $lit = false;
                for ($dy = 0; $dy < 4 && !$lit; $dy++) {
                    $lit = nibbleAt($payload, $blockLightBase, $bx & 15, $bz & 15, ($by + 1 + $dy) & 0x7f) > 0;
                }
                if ($lit) {
                    $sawLitChunk = true;
                }
            }
        }
        usleep(10000);
    }
    ok($store !== null && $store->getBlock($bx, $by + 1, $bz) === 50, 'torch placed in the world');
    ok($sawLitChunk, 'chunk re-sent with lit block light after the torch placement');

    // Clean up: break the torch so later tests see an unchanged world.
    if ($store !== null && $store->getBlock($bx, $by + 1, $bz) === 50) {
        $store->setBlock($bx, $by + 1, $bz, 0, 0);
        $store->recalculateLight(intdiv($bx, 16), intdiv($bz, 16), $kernel->getWorld()->getResourceRegistry()->get(\pocketmine\core\resource\BlockRegistry::class));
    }
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

test('PlayerChatEvent fires and can cancel the broadcast', function () use ($client, $kernel): void {
    // Guard: only cancel this exact message so other chat tests are unaffected.
    $kernel->getEventPort()->subscribe(\pocketmine\api\event\PlayerChatEvent::class, function (\pocketmine\api\event\PlayerChatEvent $e): void {
        if ($e->getMessage() === 'event-cancel-me') {
            $e->setCancelled(true);
        }
    });

    $chat = new TextPacket();
    $chat->type = TextPacket::TYPE_CHAT;
    $chat->source = 'Alice';
    $chat->message = 'event-cancel-me';
    $client->sendGamePacket($chat);
    $kernel->run(2);

    // A cancelled chat must NOT be echoed back within a generous window.
    $deadline = microtime(true) + 1.5;
    $echoes = 0;
    while (microtime(true) < $deadline) {
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::TEXT_PACKET) {
                $tp = textPacket($buffer);
                if ($tp['type'] === TextPacket::TYPE_RAW && str_contains($tp['message'], 'event-cancel-me')) {
                    $echoes++;
                }
            }
        }
        $kernel->run(1);
        usleep(5000);
    }
    same(0, $echoes, 'cancelled chat is never broadcast');
});

test('PlayerChatEvent can rewrite the message before broadcast', function () use ($client, $kernel): void {
    $kernel->getEventPort()->subscribe(\pocketmine\api\event\PlayerChatEvent::class, function (\pocketmine\api\event\PlayerChatEvent $e): void {
        if ($e->getMessage() === 'event-rewrite-me') {
            $e->setMessage('rewritten by plugin');
        }
    });

    $chat = new TextPacket();
    $chat->type = TextPacket::TYPE_CHAT;
    $chat->source = 'Alice';
    $chat->message = 'event-rewrite-me';
    $client->sendGamePacket($chat);
    $kernel->run(1);

    $deadline = microtime(true) + 3.0;
    while (microtime(true) < $deadline) {
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::TEXT_PACKET) {
                $tp = textPacket($buffer);
                if ($tp['type'] === TextPacket::TYPE_RAW) {
                    same('Alice: rewritten by plugin', $tp['message'], 'rewritten message broadcast');
                    return;
                }
            }
        }
        $kernel->run(1);
        usleep(10000);
    }
    ok(false, 'rewritten chat echo received');
});

test('PlayerMoveEvent fires with from/to on an accepted move', function () use ($client, $kernel): void {
    $before = null;
    foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
        if ($p['username'] === 'Alice') {
            $before = $p;
        }
    }
    if ($before === null) {
        ok(false, 'Alice is online');
        return;
    }

    $from = null;
    $to = null;
    $kernel->getEventPort()->subscribe(\pocketmine\api\event\PlayerMoveEvent::class, function (\pocketmine\api\event\PlayerMoveEvent $e) use (&$from, &$to): void {
        if ($e->getPlayer()->getName() !== 'Alice' || $from !== null) {
            return; // record only the first Alice move after subscribing
        }
        $from = $e->getFrom();
        $to = $e->getTo();
    });

    $tx = $before['x'] + 0.5;
    $ty = $before['y'];
    $tz = $before['z'];
    $move = new MovePlayerPacket();
    $move->eid = 0;
    $move->x = $tx;
    $move->y = $ty;
    $move->z = $tz;
    $move->yaw = 0.0;
    $move->bodyYaw = 0.0;
    $move->pitch = 0.0;
    $move->mode = MovePlayerPacket::MODE_NORMAL;
    $move->onGround = true;
    $client->sendGamePacket($move);

    $deadline = microtime(true) + 6.0;
    while (microtime(true) < $deadline && $from === null) {
        $client->readGamePackets();
        $kernel->run(1);
        usleep(10000);
    }
    ok($from !== null, 'PlayerMoveEvent fired for the accepted move');
    if ($from === null) {
        return;
    }
    near($before['x'], $from[0], 1e-6, 'event from.x is the pre-move x');
    near($tx, $to[0], 1e-6, 'event to.x is the applied x');
    near($tz, $to[2], 1e-6, 'event to.z is the applied z');
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
    // Bob2 teleports to a new spot (server-authoritative: /tp uses the same
    // sendTeleportTo path; the per-tick broadcast then relays it to Alice).
    $kernel->getNetworkSessionService()->sendTeleportTo($bob2Eid, 30.5, 66.0, 5.5);

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
    $mob = $kernel->getEntitySpawnService()->spawnMob(\pocketmine\core\enum\EntityType::Zombie, $alice['x'] + 3, $alice['y'] + 1, $alice['z']);
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
    // Tick-budgeted (not wall-clock): a wall-clock deadline under CPU load
    // (e.g. the full suite running) lets fewer kernel ticks elapse and the
    // drop can miss the 8s pickup window. 600 ticks = 30s of game time, far
    // beyond the fresh-drop delay + settle + broadcast chain.
    $sawAdd = false;
    $sawRemove = false;
    $sawSlot = false;
    $tickBudget = 600;
    while ($tickBudget-- > 0 && (!$sawAdd || !$sawRemove || !$sawSlot)) {
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

// --- Inventory actions (14.7) ----------------------------------------------
test('a player moves items between inventory slots via ContainerSetSlot', function () use ($kernel, $client): void {
    // Alice holds 32 planks in slot 0 (starter kit). A move: the client sends
    // the NEW content of the affected slots - slot 0 emptied, slot 5 = the
    // planks. The server applies the authoritative state and mirrors the
    // changed slot back (ContainerSetSlotPacket to the actor).
    $css = new ContainerSetSlotPacket();
    $css->windowid = ContainerSetContentPacket::SPECIAL_INVENTORY;
    $css->slot = 0;
    $css->hotbarSlot = 0;
    $css->item = [0, 0, 0, null]; // slot 0 emptied
    $client->sendGamePacket($css);

    $css2 = new ContainerSetSlotPacket();
    $css2->windowid = ContainerSetContentPacket::SPECIAL_INVENTORY;
    $css2->slot = 5;
    $css2->hotbarSlot = 5;
    $css2->item = [5, 32, 0, null]; // 32 planks into slot 5
    $client->sendGamePacket($css2);
    $kernel->run(2);

    // Server-side authoritative state.
    $aliceId = null;
    foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
        if ($p['username'] === 'Alice') {
            $aliceId = $p['entityId'];
        }
    }
    ok($aliceId !== null, 'Alice is online');
    $inv = $kernel->getWorld()->getEntity($aliceId)?->get(\pocketmine\core\component\InventoryComponent::class);
    ok($inv !== null, 'Alice inventory present');
    if ($inv === null) {
        return;
    }
    ok($inv->get(0) === null, 'slot 0 emptied by the move');
    $slot5 = $inv->get(5);
    ok($slot5 !== null && $slot5->itemId === 5 && $slot5->count === 32, 'slot 5 holds the moved 32 planks');

    // The actor window is mirrored back (server -> client ContainerSetSlot).
    $deadline = microtime(true) + 5.0;
    $sawEcho = false;
    while (microtime(true) < $deadline && !$sawEcho) {
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::CONTAINER_SET_SLOT_PACKET) {
                $sawEcho = true;
            }
        }
        $kernel->run(1);
    }
    ok($sawEcho, 'server echoes the changed slot back to the actor');
});

test('a hostile slot claim cannot conjure items the player never had', function () use ($kernel, $client): void {
    // A malicious client claims 64 diamonds (id 57) into slot 8 with no
    // matching move credit. The server must reject the claim and keep the
    // authoritative slot state (null) unchanged.
    $css = new ContainerSetSlotPacket();
    $css->windowid = ContainerSetContentPacket::SPECIAL_INVENTORY;
    $css->slot = 8;
    $css->hotbarSlot = 8;
    $css->item = [57, 64, 0, null]; // 64 diamonds
    $client->sendGamePacket($css);
    $kernel->run(2);

    $aliceId = null;
    foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
        if ($p['username'] === 'Alice') {
            $aliceId = $p['entityId'];
        }
    }
    ok($aliceId !== null, 'Alice is online');
    $inv = $kernel->getWorld()->getEntity($aliceId)?->get(\pocketmine\core\component\InventoryComponent::class);
    ok($inv !== null, 'Alice inventory present');
    if ($inv !== null) {
        ok($inv->get(8) === null, 'conjured diamonds rejected (slot 8 still empty)');
    }
});

test('a player drops an item with Q and it spawns as an item entity', function () use ($kernel, $client): void {
    // Give Alice a fresh full stack in the held slot (server-side) so the
    // drop has a deterministic source regardless of earlier tests.
    $aliceId = null;
    foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
        if ($p['username'] === 'Alice') {
            $aliceId = $p['entityId'];
        }
    }
    ok($aliceId !== null, 'Alice is online');
    $entity = $kernel->getWorld()->getEntity($aliceId);
    $inv = $entity?->get(\pocketmine\core\component\InventoryComponent::class);
    ok($inv !== null, 'Alice inventory present');
    if ($inv === null) {
        return;
    }
    $inv->set(0, new \pocketmine\core\component\ItemStack(5, 0, 10)); // 10 planks held
    $inv->setHeldSlot(0);

    // Q press: DropItemPacket with the held item (id 5, count 1).
    $drop = new DropItemPacket();
    $drop->type = 0;
    $drop->item = [5, 1, 0, null];
    $client->sendGamePacket($drop);

    // The server removes one plank and spawns a real item entity that the
    // per-tick broadcast adds to the viewer (AddItemEntityPacket id 5),
    // while the actor's slot reflects 9 remaining.
    $deadline = microtime(true) + 6.0;
    $sawAdd = false;
    $sawSlot9 = false;
    while (microtime(true) < $deadline && (!$sawAdd || !$sawSlot9)) {
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::ADD_ITEM_ENTITY_PACKET) {
                $aie = aieFields($buffer);
                if ($aie['item'][0] === 5 && $aie['item'][1] === 1) {
                    $sawAdd = true;
                }
            }
            if ($id === Info::CONTAINER_SET_SLOT_PACKET) {
                $css = cssFields($buffer);
                if ($css['slot'] === 0 && $css['item'][0] === 5 && $css['item'][1] === 9) {
                    $sawSlot9 = true;
                }
            }
        }
        $kernel->run(1);
    }
    ok($sawAdd, 'dropped item spawned as an AddItemEntityPacket (1 plank)');
    ok($sawSlot9, 'held slot synced back to 9 planks after the drop');
    $heldAfter = $inv->get(0);
    ok($heldAfter !== null && $heldAfter->count === 9, 'server inventory holds 9 planks after the drop');

    // A bogus drop of an item the player does not hold is a no-op.
    $before = $inv->get(0)?->count;
    $drop2 = new DropItemPacket();
    $drop2->type = 0;
    $drop2->item = [266, 1, 0, null]; // iron ingot - Alice has none
    $client->sendGamePacket($drop2);
    $kernel->run(2);
    ok($inv->get(0)?->count === $before, 'dropping an unheld item changes nothing');
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
    $mob = $kernel->getEntitySpawnService()->spawnMob(\pocketmine\core\enum\EntityType::Zombie, $alice['x'] + 2.5, $alice['y'] - 1.0, $alice['z']);
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

// --- Tool durability (14.10) -----------------------------------------------
test('a held tool degrades with each block broken and syncs the slot', function () use ($client, $kernel): void {
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
    $inv = $kernel->getWorld()->getEntity($alice['entityId'])?->get(\pocketmine\core\component\InventoryComponent::class);
    ok($inv !== null, 'Alice inventory present');
    if ($inv === null) {
        return;
    }
    // Deterministic target: a fresh dirt block two cells east of Alice at her
    // feet height, written through the same ChunkStore the services use (no
    // seed-random terrain scanning / teleport timing involved).
    $store = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);
    $store = $store instanceof \pocketmine\core\resource\ChunkStore ? $store : null;
    if ($store === null) {
        ok(false, 'chunk store present');
        return;
    }
    $tx = (int)floor($alice['x']) + 2;
    $ty = (int)floor($alice['y']);
    $tz = (int)floor($alice['z']);
    $store->setBlock($tx, $ty, $tz, 3, 0); // dirt

    // Wooden pickaxe (270, max durability 59): the meta IS the damage.
    $inv->set(0, new \pocketmine\core\component\ItemStack(270, 0, 1));
    $inv->setHeldSlot(0);

    $aliceRef = \pocketmine\core\ecs\EntityRef::create($alice['entityId'], $kernel->getWorld());
    ok($kernel->getBlockBreakService()->breakBlock($aliceRef, $tx, $ty, $tz, 1), 'dirt block broke');
    $kernel->run(1); // flush the outbound slot sync

    $held = $inv->get(0);
    ok($held !== null && $held->itemId === 270 && $held->meta === 1, 'held pickaxe took one point of wear (meta 1)');

    // The degraded meta must reach the client so the durability bar updates.
    $deadline = microtime(true) + 3.0;
    $sawWorn = false;
    while (microtime(true) < $deadline && !$sawWorn) {
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::CONTAINER_SET_SLOT_PACKET) {
                $css = cssFields($buffer);
                if ($css['slot'] === 0 && $css['item'][0] === 270 && $css['item'][2] === 1) {
                    $sawWorn = true;
                }
            }
        }
        $kernel->run(1);
    }
    ok($sawWorn, 'worn tool meta synced to the client via ContainerSetSlot');
});

test('a tool breaks into air when its damage reaches max durability', function () use ($client, $kernel): void {
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
    $inv = $kernel->getWorld()->getEntity($alice['entityId'])?->get(\pocketmine\core\component\InventoryComponent::class);
    if ($inv === null) {
        ok(false, 'Alice inventory present');
        return;
    }
    // Fresh dirt block two cells east of Alice, at her feet height.
    $store = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);
    $store = $store instanceof \pocketmine\core\resource\ChunkStore ? $store : null;
    if ($store === null) {
        ok(false, 'chunk store present');
        return;
    }
    $tx = (int)floor($alice['x']) + 2;
    $ty = (int)floor($alice['y']);
    $tz = (int)floor($alice['z']);
    $store->setBlock($tx, $ty, $tz, 3, 0); // dirt

    // Wooden pickaxe at 58/59: the next break destroys it (59 >= 59).
    $inv->set(0, new \pocketmine\core\component\ItemStack(270, 58, 1));
    $inv->setHeldSlot(0);

    $aliceRef = \pocketmine\core\ecs\EntityRef::create($alice['entityId'], $kernel->getWorld());
    ok($kernel->getBlockBreakService()->breakBlock($aliceRef, $tx, $ty, $tz, 1), 'dirt block broke');
    $kernel->run(1);

    ok($inv->get(0) === null, 'broken tool removed from the held slot');

    // The client is told the slot now holds air.
    $deadline = microtime(true) + 3.0;
    $sawAir = false;
    while (microtime(true) < $deadline && !$sawAir) {
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::CONTAINER_SET_SLOT_PACKET) {
                $css = cssFields($buffer);
                if ($css['slot'] === 0 && $css['item'][0] === 0) {
                    $sawAir = true;
                }
            }
        }
        $kernel->run(1);
    }
    ok($sawAir, 'broken tool synced to the client as an empty slot');
});

test('a sword wears out on a landed attack', function () use ($kernel): void {
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
    $inv = $kernel->getWorld()->getEntity($alice['entityId'])?->get(\pocketmine\core\component\InventoryComponent::class);
    if ($inv === null) {
        ok(false, 'Alice inventory present');
        return;
    }
    // Wooden sword (268, max durability 59) in the held slot.
    $inv->set(0, new \pocketmine\core\component\ItemStack(268, 0, 1));
    $inv->setHeldSlot(0);

    $mob = $kernel->getEntitySpawnService()->spawnMob(\pocketmine\core\enum\EntityType::Zombie, $alice['x'] + 2.5, $alice['y'] - 1.0, $alice['z']);
    $mobRef = \pocketmine\core\ecs\EntityRef::create($mob->getId(), $kernel->getWorld());
    $aliceRef = \pocketmine\core\ecs\EntityRef::create($alice['entityId'], $kernel->getWorld());

    $healthBefore = $kernel->getWorld()->getEntity($mob->getId())?->get(\pocketmine\core\component\HealthComponent::class)?->current;
    $landed = $kernel->getEntityInteractionService()->attack($aliceRef, $mobRef);
    $healthAfter = $kernel->getWorld()->getEntity($mob->getId())?->get(\pocketmine\core\component\HealthComponent::class)?->current;

    ok($landed, 'attack landed');
    ok($healthAfter !== null && $healthAfter < ($healthBefore ?? 20.0), 'mob took damage');
    $held = $inv->get(0);
    ok($held !== null && $held->itemId === 268 && $held->meta === 1, 'held sword took one point of wear (meta 1)');
    $kernel->getEntityDespawnService()->despawn($mob);
});

// --- Armor + equipment (14.13) ---------------------------------------------
test('the armor window equips gear and broadcasts it to everyone', function () use ($client, $kernel): void {
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
    $entity = $kernel->getWorld()->getEntity($alice['entityId']);
    $inv = $entity?->get(\pocketmine\core\component\InventoryComponent::class);
    if ($inv === null) {
        ok(false, 'Alice inventory present');
        return;
    }

    // Deterministic start: despawn stray drop entities and clear the inventory.
    foreach ($kernel->getWorld()->getEntities() as $e) {
        if ($e->has('item')) {
            $kernel->getWorld()->despawn($e);
        }
    }
    $inv->clear();
    // The client equips a helmet the way a real 0.15 client does: empty the
    // inventory source slot first (releasing move credit), then fill armor
    // slot 0 through the 0x78 armor window.
    $inv->set(9, new \pocketmine\core\component\ItemStack(306, 0, 1)); // iron helmet

    $empty = new ContainerSetSlotPacket();
    $empty->windowid = ContainerSetContentPacket::SPECIAL_INVENTORY;
    $empty->slot = 9;
    $empty->hotbarSlot = 0;
    $empty->item = [0, 0, 0, null];
    $client->sendGamePacket($empty);

    $equip = new ContainerSetSlotPacket();
    $equip->windowid = ContainerSetContentPacket::SPECIAL_ARMOR;
    $equip->slot = 0;
    $equip->hotbarSlot = 0;
    $equip->item = [306, 1, 0, null];
    $client->sendGamePacket($equip);

    $deadline = microtime(true) + 4.0;
    $sawMirror = false;
    $sawBroadcast = false;
    while (microtime(true) < $deadline && !($sawMirror && $sawBroadcast)) {
        $kernel->run(1);
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::CONTAINER_SET_SLOT_PACKET) {
                $css = cssFields($buffer);
                if ($css['windowid'] === ContainerSetContentPacket::SPECIAL_ARMOR
                    && $css['slot'] === 0 && $css['item'][0] === 306) {
                    $sawMirror = true;
                }
            }
            if ($id === Info::MOB_ARMOR_EQUIPMENT_PACKET) {
                // Skip the leading packet-id byte (see cscFields).
                $bs = new BinaryStream($buffer, 1);
                $eid = $bs->getLong();
                $helmet = $bs->getSlot();
                if ($eid === $alice['entityId'] && $helmet[0] === 306) {
                    $sawBroadcast = true;
                }
            }
        }
        usleep(10000);
    }
    $equipped = $inv->get(36);
    ok($equipped !== null && $equipped->itemId === 306, 'helmet stored in armor slot 36');
    ok($inv->get(9) === null, 'inventory slot 9 emptied by the move');
    ok($sawMirror, 'armor slot mirrored back through window 0x78');
    ok($sawBroadcast, 'MobArmorEquipmentPacket broadcast to viewers');
});

test('armor reduces attack damage', function () use ($kernel): void {
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
    $entity = $kernel->getWorld()->getEntity($alice['entityId']);
    $inv = $entity?->get(\pocketmine\core\component\InventoryComponent::class);
    if ($inv === null) {
        ok(false, 'Alice inventory present');
        return;
    }
    $aliceRef = \pocketmine\core\ecs\EntityRef::create($alice['entityId'], $kernel->getWorld());

    // One zombie hit against Alice, returning the HP drop. Alice is re-fetched
    // per hit so knockback drift cannot put the mob out of reach.
    $drop = function () use ($kernel): float {
        $alice = null;
        foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
            if ($p['username'] === 'Alice') {
                $alice = $p;
                break;
            }
        }
        if ($alice === null) {
            return 0.0;
        }
        $mob = $kernel->getEntitySpawnService()->spawnMob(\pocketmine\core\enum\EntityType::Zombie, $alice['x'] + 2.5, $alice['y'] - 1.0, $alice['z']);
        $mobRef = \pocketmine\core\ecs\EntityRef::create($mob->getId(), $kernel->getWorld());
        $aliceRef = \pocketmine\core\ecs\EntityRef::create($alice['entityId'], $kernel->getWorld());
        $healthBefore = $kernel->getWorld()->getEntity($alice['entityId'])?->get(\pocketmine\core\component\HealthComponent::class)?->current ?? 20.0;
        $kernel->getEntityInteractionService()->attack($mobRef, $aliceRef);
        $healthAfter = $kernel->getWorld()->getEntity($alice['entityId'])?->get(\pocketmine\core\component\HealthComponent::class)?->current ?? 0.0;
        $kernel->getEntityDespawnService()->despawn($mob);
        return max(0.0, $healthBefore - $healthAfter);
    };

    // Unarmored baseline.
    $inv->clear();
    $kernel->getCombatService()->heal($aliceRef, 20);
    $baseline = $drop();

    // Full diamond set (helmet/chest/legs/boots = 310-313, 56% reduction).
    $inv->clear();
    $inv->set(36, new \pocketmine\core\component\ItemStack(310, 0, 1));
    $inv->set(37, new \pocketmine\core\component\ItemStack(311, 0, 1));
    $inv->set(38, new \pocketmine\core\component\ItemStack(312, 0, 1));
    $inv->set(39, new \pocketmine\core\component\ItemStack(313, 0, 1));
    $kernel->getCombatService()->heal($aliceRef, 20);
    $armored = $drop();

    ok($baseline > 0, 'an unarmored zombie hit deals damage');
    ok($armored < $baseline, 'armor reduces the damage taken');
    ok($armored <= $baseline * 0.8 + 0.01, 'full diamond cuts the damage by roughly half or more');

    $kernel->getCombatService()->heal($aliceRef, 20);
    $inv->clear();
});

test('armor wears out on hits and breaks at max durability', function () use ($kernel): void {
    $alice = null;
    foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
        if ($p['username'] === 'Alice') {
            $alice = $p;
            break;
        }
    }
    if ($alice === null) {
        ok(false, 'Alice is online');
        return;
    }
    $entity = $kernel->getWorld()->getEntity($alice['entityId']);
    $inv = $entity?->get(\pocketmine\core\component\InventoryComponent::class);
    if ($inv === null) {
        ok(false, 'Alice inventory present');
        return;
    }
    $aliceRef = \pocketmine\core\ecs\EntityRef::create($alice['entityId'], $kernel->getWorld());
    $inv->clear();

    // One zombie hit against Alice, then heal her back to full. The zombie is
    // spawned at Alice's current position so knockback cannot break reach.
    $hit = function () use ($kernel): void {
        $alice = null;
        foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
            if ($p['username'] === 'Alice') {
                $alice = $p;
                break;
            }
        }
        if ($alice === null) {
            return;
        }
        $mob = $kernel->getEntitySpawnService()->spawnMob(\pocketmine\core\enum\EntityType::Zombie, $alice['x'] + 2.5, $alice['y'] - 1.0, $alice['z']);
        $mobRef = \pocketmine\core\ecs\EntityRef::create($mob->getId(), $kernel->getWorld());
        $aliceRef = \pocketmine\core\ecs\EntityRef::create($alice['entityId'], $kernel->getWorld());
        $kernel->getCombatService()->heal($aliceRef, 20);
        $kernel->getEntityInteractionService()->attack($mobRef, $aliceRef);
        $kernel->getEntityDespawnService()->despawn($mob);
    };

    // Wear: a fresh leather helmet (298, 55 max durability) gains damage on
    // hits. Wear is random (2 hits in 3), so poll until observed (P(no wear
    // in 30 hits) is negligible).
    $inv->set(36, new \pocketmine\core\component\ItemStack(298, 0, 1));
    $wore = false;
    for ($i = 0; $i < 30 && !$wore; $i++) {
        $hit();
        $helmet = $inv->get(36);
        if ($helmet !== null && $helmet->meta >= 3) {
            $wore = true;
        }
    }
    ok($wore, 'armor took durability damage from hits');

    // Break: a piece one hit from max durability (meta 53, +3 per wear) is
    // removed when it hits the cap.
    $inv->set(36, new \pocketmine\core\component\ItemStack(298, 53, 1));
    $broke = false;
    for ($i = 0; $i < 30 && !$broke; $i++) {
        $hit();
        if ($inv->get(36) === null) {
            $broke = true;
        }
    }
    ok($broke, 'armor broke and was removed at max durability');

    $kernel->getCombatService()->heal($aliceRef, 20);
    $inv->clear();
});

// --- Crafting (14.12) ------------------------------------------------------
test('a 2x2 craft consumes ingredients and grants the result over the wire', function () use ($client, $kernel): void {
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
    $entity = $kernel->getWorld()->getEntity($alice['entityId']);
    $inv = $entity?->get(\pocketmine\core\component\InventoryComponent::class);
    if ($inv === null) {
        ok(false, 'Alice inventory present');
        return;
    }

    // Deterministic start: earlier break tests left drop entities lying near
    // Alice that the pickup system would shovel into her inventory mid-test.
    // Despawn every item entity, clear the inventory, then put exactly 4
    // planks in slot 0 (the 2x2 crafting grid draws from the player
    // inventory).
    foreach ($kernel->getWorld()->getEntities() as $e) {
        if ($e->has('item')) {
            $kernel->getWorld()->despawn($e);
        }
    }
    $inv->clear();
    $inv->set(0, new \pocketmine\core\component\ItemStack(5, 0, 4));
    $inv->setHeldSlot(0);

    // Client crafts a crafting table: 2x2 grid of four planks -> id 58.
    $craft = new CraftingEventPacket();
    $craft->windowId = 0x79; // player 2x2 crafting grid
    $craft->type = 0;        // small crafting
    $craft->id = \pocketmine\utils\UUID::fromData('crafting_table');
    $craft->input = [[5, 1, 0, null], [5, 1, 0, null], [5, 1, 0, null], [5, 1, 0, null]];
    $craft->output = [[58, 1, 0, null]];
    $client->sendGamePacket($craft);

    $deadline = microtime(true) + 4.0;
    $sawTable = false;
    $sawResync = false;
    while (microtime(true) < $deadline && !$sawTable) {
        $kernel->run(1);
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::CONTAINER_SET_CONTENT_PACKET) {
                $csc = cscFields($buffer);
                if ($csc['windowid'] === 0) {
                    foreach ($csc['slots'] as $slot) {
                        if ($slot[0] === 58) {
                            $sawResync = true;
                        }
                    }
                }
            }
        }
        $planks = 0;
        $tables = 0;
        foreach ($inv->getContents() as $item) {
            if ($item === null) {
                continue;
            }
            if ($item->itemId === 5) {
                $planks += $item->count;
            }
            if ($item->itemId === 58) {
                $tables += $item->count;
            }
        }
        if ($tables >= 1 && $planks <= 0) {
            $sawTable = true;
        }
        usleep(10000);
    }
    ok($tables >= 1, 'crafting table granted');
    same(0, $planks, 'no planks left after the craft');
    ok($sawResync, 'inventory contents resynced to the client after the craft');
});

// --- Food + hunger (14.11) -------------------------------------------------
test('a player eats food to restore hunger and the HUD bar updates', function () use ($client, $kernel): void {
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
    $entity = $kernel->getWorld()->getEntity($alice['entityId']);
    $inv = $entity?->get(\pocketmine\core\component\InventoryComponent::class);
    $hc = $entity?->get(\pocketmine\core\component\HungerComponent::class);
    if ($inv === null || $hc === null) {
        ok(false, 'Alice inventory + hunger present');
        return;
    }
    // Deterministic starting state: half-full food bar.
    $hc->hunger = 10.0;
    $hc->saturation = 5.0;
    $hc->exhaustion = 0.0;
    $hc->lastSyncedHunger = 10.0;
    $hc->lastSyncedSaturation = 5.0;
    // Apple (260) x3 in the held slot.
    $inv->set(0, new \pocketmine\core\component\ItemStack(260, 0, 3));
    $inv->setHeldSlot(0);

    // Right-click with the apple: USE_ITEM routes to the eat path.
    $use = new UseItemPacket();
    $use->x = (int)floor($alice['x']);
    $use->y = (int)floor($alice['y']);
    $use->z = (int)floor($alice['z']);
    $use->face = 0;
    $use->fx = 0.0;
    $use->fy = 0.0;
    $use->fz = 0.0;
    $use->posX = $alice['x'];
    $use->posY = $alice['y'];
    $use->posZ = $alice['z'];
    $use->slot = 0;
    $use->item = [260, 1, 0, null];
    $client->sendGamePacket($use);

    // The USE_ITEM packet travels socket -> RakNet thread -> kernel: poll
    // until the eat lands. Apple restores 4 hunger + 2.4 saturation (legacy
    // Food values) and consumes one item.
    $deadline = microtime(true) + 4.0;
    while (microtime(true) < $deadline && $hc->hunger < 14.0) {
        $kernel->run(1);
        usleep(10000);
    }
    ok($hc->hunger === 14.0, 'hunger restored to 14 (10 + 4)');
    ok(abs($hc->saturation - 7.4) < 0.001, 'saturation raised to 7.4 (5 + 2.4)');
    $held = $inv->get(0);
    ok($held !== null && $held->count === 2, 'one apple consumed (2 left)');

    // The HUD food bar must follow (player.hunger attribute, eid 0).
    $deadline = microtime(true) + 3.0;
    $sawBar = false;
    while (microtime(true) < $deadline && !$sawBar) {
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::UPDATE_ATTRIBUTES_PACKET) {
                $ua = uaFields($buffer);
                if (isset($ua['entries']['player.hunger']) && abs($ua['entries']['player.hunger']['value'] - 14.0) < 0.001) {
                    $sawBar = true;
                }
            }
        }
        $kernel->run(1);
    }
    ok($sawBar, 'HUD hunger bar synced to 14 via UpdateAttributesPacket');
});

test('hunger drains as exhaustion builds up past the threshold', function () use ($kernel): void {
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
    $hc = $kernel->getWorld()->getEntity($alice['entityId'])?->get(\pocketmine\core\component\HungerComponent::class);
    if ($hc === null) {
        ok(false, 'Alice hunger present');
        return;
    }
    // No saturation left, exhaustion 0.1 below the 4.0 drain threshold.
    $hc->hunger = 20.0;
    $hc->saturation = 0.0;
    $hc->exhaustion = 3.8;

    // ~0.01 passive exhaustion per tick: after 30 ticks exhaustion passes
    // 4.0 exactly once, costing 1 hunger (saturation is already 0).
    $world = $kernel->getWorld();
    for ($i = 0; $i < 30; $i++) {
        $world->tick(0.05);
    }
    ok($hc->hunger === 19.0, 'hunger drained by 1 when exhaustion hit 4.0 (' . var_export($hc->hunger, true) . ')');
});

test('a starving player takes damage at zero hunger', function () use ($kernel): void {
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
    $entity = $kernel->getWorld()->getEntity($alice['entityId']);
    $hc = $entity?->get(\pocketmine\core\component\HungerComponent::class);
    $health = $entity?->get(\pocketmine\core\component\HealthComponent::class);
    if ($hc === null || $health === null) {
        ok(false, 'Alice hunger + health present');
        return;
    }
    $hc->hunger = 0.0;
    $hc->exhaustion = 0.0;
    $health->current = 20.0;

    // RegenSystem is gated on hunger > 0, so at zero hunger only the
    // starvation damage applies: 1 HP per 80 ticks (the window starts on the
    // first tick, so 81 ticks are needed to see the first damage).
    $world = $kernel->getWorld();
    for ($i = 0; $i < 81; $i++) {
        $world->tick(0.05);
    }
    ok($health->current < 20.0, 'starving player took damage (' . var_export($health->current, true) . ' HP)');
    $hc->hunger = 20.0; // restore so later tests are unaffected
});

test('attacking and mining add exhaustion', function () use ($kernel): void {
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
    $entity = $kernel->getWorld()->getEntity($alice['entityId']);
    $inv = $entity?->get(\pocketmine\core\component\InventoryComponent::class);
    $hc = $entity?->get(\pocketmine\core\component\HungerComponent::class);
    if ($inv === null || $hc === null) {
        ok(false, 'Alice inventory + hunger present');
        return;
    }
    $hc->exhaustion = 0.0;

    // Mining a fresh dirt block (legacy CAUSE_MINING 0.025).
    $store = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);
    $store = $store instanceof \pocketmine\core\resource\ChunkStore ? $store : null;
    if ($store === null) {
        ok(false, 'chunk store present');
        return;
    }
    $tx = (int)floor($alice['x']) + 2;
    $ty = (int)floor($alice['y']);
    $tz = (int)floor($alice['z']);
    $store->setBlock($tx, $ty, $tz, 3, 0);
    $aliceRef = \pocketmine\core\ecs\EntityRef::create($alice['entityId'], $kernel->getWorld());
    ok($kernel->getBlockBreakService()->breakBlock($aliceRef, $tx, $ty, $tz, 1), 'dirt block broke');
    ok(abs($hc->exhaustion - 0.025) < 0.001, 'mining added 0.025 exhaustion');

    // A landed sword hit (legacy CAUSE_ATTACK 0.3).
    $inv->set(0, new \pocketmine\core\component\ItemStack(268, 0, 1));
    $inv->setHeldSlot(0);
    $mob = $kernel->getEntitySpawnService()->spawnMob(\pocketmine\core\enum\EntityType::Zombie, $alice['x'] + 2.5, $alice['y'] - 1.0, $alice['z']);
    $mobRef = \pocketmine\core\ecs\EntityRef::create($mob->getId(), $kernel->getWorld());
    $landed = $kernel->getEntityInteractionService()->attack($aliceRef, $mobRef);
    ok($landed, 'attack landed');
    ok(abs($hc->exhaustion - 0.325) < 0.001, 'attack added 0.3 exhaustion (total 0.325)');
    $kernel->getEntityDespawnService()->despawn($mob);
});

// --- XP orbs + health regen (14.9) -----------------------------------------
test('an XP orb dropped by a mob death is broadcast as AddEntityPacket type 69', function () use ($kernel, $client): void {
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

    // Kill a zombie right next to Alice: the death pipeline drops an XP orb
    // (CombatService::dropExperience) at the death position.
    $mob = $kernel->getEntitySpawnService()->spawnMob(\pocketmine\core\enum\EntityType::Zombie, $alice['x'] + 2.0, $alice['y'] - 1.0, $alice['z']);
    $mobRef = \pocketmine\core\ecs\EntityRef::create($mob->getId(), $kernel->getWorld());
    $kernel->getCombatService()->applyDamage($mobRef, 1000.0);

    $deadline = microtime(true) + 5.0;
    $sawOrb = false;
    while (microtime(true) < $deadline && !$sawOrb) {
        $kernel->run(1);
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::ADD_ENTITY_PACKET) {
                $ae = aeFields($buffer);
                if ($ae['type'] === 69) {
                    $sawOrb = true;
                }
            }
        }
        usleep(10000);
    }
    ok($sawOrb, 'XP orb broadcast as AddEntityPacket with legacy type 69');

    // Clean up any leftover orbs so later tests are deterministic.
    foreach ($kernel->getWorld()->getEntities() as $entity) {
        if ($entity->has('xp_orb')) {
            $kernel->getWorld()->despawn($entity);
        }
    }
    $kernel->getWorld()->tick(0.05);
});

test('a player collects an XP orb and the HUD level attribute updates', function () use ($kernel, $client): void {
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

    // Drop an XP orb right at Alice's feet (5 XP) and let the per-tick
    // walk-over system collect it into her XP metadata.
    $orb = $kernel->getWorld()->spawn(
        (new \pocketmine\core\ecs\EntityBuilder())
            ->with(new \pocketmine\core\component\PositionComponent($alice['x'], $alice['y'], $alice['z']))
            ->with(new \pocketmine\core\component\VelocityComponent())
            ->with(new \pocketmine\core\component\HealthComponent(1, 1))
            ->with(new \pocketmine\core\component\MetadataComponent(['xp' => 5]))
            ->withTag('xp_orb')
    );
    $orbEid = $orb->getId();

    $deadline = microtime(true) + 5.0;
    $sawXp = false;
    $sawLevel = false;
    while (microtime(true) < $deadline && (!$sawXp || !$sawLevel)) {
        $kernel->run(1);
        // The orb entity should be gone: collected by the pickup system.
        if ($kernel->getWorld()->getEntity($orbEid) === null) {
            $sawXp = true;
        }
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::UPDATE_ATTRIBUTES_PACKET) {
                $ua = uaFields($buffer);
                if (isset($ua['entries']['player.level'])) {
                    $sawLevel = true;
                }
            }
        }
        usleep(10000);
    }
    ok($sawXp, 'XP orb collected by walk-over (despawned)');
    ok($sawLevel, 'UpdateAttributesPacket with player.level sent to the HUD');

    // The player's XP metadata must reflect the collected orb.
    $playerEntity = null;
    foreach ($kernel->getWorld()->getEntities() as $entity) {
        if ($entity->has(\pocketmine\core\component\tags\PlayerTag::class)) {
            $playerEntity = $entity;
            break;
        }
    }
    $xp = $playerEntity?->get(\pocketmine\core\component\MetadataComponent::class)?->get('xp', 0);
    ok($xp !== null && (int)$xp >= 5, 'player XP metadata credited (' . var_export($xp, true) . ')');
});

test('player health regenerates after a no-damage window', function () use ($kernel, $client): void {
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

    // Damage Alice to half health through the combat pipeline.
    $aliceRef = \pocketmine\core\ecs\EntityRef::create($alice['entityId'], $kernel->getWorld());
    $kernel->getCombatService()->applyDamage($aliceRef, 10.0);
    $healthAfter = $kernel->getWorld()->getEntity($alice['entityId'])?->get(\pocketmine\core\component\HealthComponent::class)?->current;
    ok($healthAfter !== null && $healthAfter < 20.0, 'Alice damaged below max health');

    // RegenSystem: 1 HP per 80 ticks after a 100-tick no-damage window.
    // Run world ticks directly (no kernel sleep) so the test is fast; the
    // system keeps its own tick counter.
    $world = $kernel->getWorld();
    for ($i = 0; $i < 100 + 80 + 5; $i++) {
        $world->tick(0.05);
    }
    $healthRegen = $world->getEntity($alice['entityId'])?->get(\pocketmine\core\component\HealthComponent::class)?->current;
    ok($healthRegen !== null && $healthRegen > $healthAfter, 'health regenerated after the no-damage window (' . var_export($healthRegen, true) . ')');

    // The HUD must follow: one kernel tick flushes a SetHealthPacket.
    $kernel->run(1);
    $deadline = microtime(true) + 3.0;
    $sawHealth = false;
    while (microtime(true) < $deadline && !$sawHealth) {
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::SET_HEALTH_PACKET) {
                $sawHealth = true;
            }
        }
        $kernel->run(1);
        usleep(10000);
    }
    ok($sawHealth, 'SetHealthPacket reflects the regenerated health on the HUD');
});

// --- Commands (14.14) ------------------------------------------------------
test('a /gamemode command flips the client and the metadata', function () use ($client, $kernel): void {
    $alice = null;
    foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
        if ($p['username'] === 'Alice') {
            $alice = $p;
            break;
        }
    }
    if ($alice === null) {
        ok(false, 'Alice is online');
        return;
    }
    $entity = $kernel->getWorld()->getEntity($alice['entityId']);
    $meta = $entity?->get(\pocketmine\core\component\MetadataComponent::class);
    if ($meta === null) {
        ok(false, 'Alice metadata present');
        return;
    }

    $cmd = new TextPacket();
    $cmd->type = TextPacket::TYPE_CHAT;
    $cmd->source = 'Alice';
    $cmd->message = '/gamemode 1';
    $client->sendGamePacket($cmd);

    $deadline = microtime(true) + 3.0;
    $sawCreative = false;
    $sawReply = false;
    while (microtime(true) < $deadline && !($sawCreative && $sawReply)) {
        $kernel->run(1);
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::ADVENTURE_SETTINGS_PACKET) {
                if ((advFields($buffer)['flags'] & 0x80) !== 0) {
                    $sawCreative = true; // allow-fly bit (0x80) => creative settings
                }
            }
            if ($id === Info::TEXT_PACKET) {
                $tp = textPacket($buffer);
                if (str_contains($tp['message'] ?? '', 'creative')) {
                    $sawReply = true;
                }
            }
        }
        usleep(10000);
    }
    same(1, $meta->get('gamemode'), 'gamemode metadata set to creative');
    ok($sawCreative, 'creative adventure-settings flags sent');
    ok($sawReply, 'command reply sent back to the sender');

    // Back to survival so the rest of the suite runs in survival.
    $cmd->message = '/gamemode 0';
    $client->sendGamePacket($cmd);
    $deadline = microtime(true) + 3.0;
    $back = false;
    while (microtime(true) < $deadline && !$back) {
        $kernel->run(1);
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::TEXT_PACKET
                && str_contains(textPacket($buffer)['message'] ?? '', 'survival')) {
                $back = true;
            }
        }
        usleep(10000);
    }
    same(0, $meta->get('gamemode'), 'gamemode metadata back to survival');
});

test('a /give command grants items and syncs the window', function () use ($client, $kernel): void {
    $alice = null;
    foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
        if ($p['username'] === 'Alice') {
            $alice = $p;
            break;
        }
    }
    if ($alice === null) {
        ok(false, 'Alice is online');
        return;
    }
    $inv = $kernel->getWorld()->getEntity($alice['entityId'])?->get(\pocketmine\core\component\InventoryComponent::class);
    if ($inv === null) {
        ok(false, 'Alice inventory present');
        return;
    }
    $inv->clear();

    $cmd = new TextPacket();
    $cmd->type = TextPacket::TYPE_CHAT;
    $cmd->source = 'Alice';
    $cmd->message = '/give Alice 264 5';
    $client->sendGamePacket($cmd);

    $deadline = microtime(true) + 3.0;
    $sawReply = false;
    while (microtime(true) < $deadline && !$sawReply) {
        $kernel->run(1);
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::TEXT_PACKET) {
                $tp = textPacket($buffer);
                if (str_contains($tp['message'] ?? '', '5 x Diamond')) {
                    $sawReply = true;
                }
            }
        }
        usleep(10000);
    }
    $stack = $inv->get(0);
    ok($stack !== null && $stack->itemId === 264 && $stack->count === 5, 'five diamonds landed in slot 0');
    ok($sawReply, '/give reply received');
    $inv->clear();
});

test('a /tp command teleports the player', function () use ($client, $kernel): void {
    $alice = null;
    foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
        if ($p['username'] === 'Alice') {
            $alice = $p;
            break;
        }
    }
    if ($alice === null) {
        ok(false, 'Alice is online');
        return;
    }
    $entity = $kernel->getWorld()->getEntity($alice['entityId']);
    $pos = $entity?->get(\pocketmine\core\component\PositionComponent::class);
    if ($pos === null) {
        ok(false, 'Alice position present');
        return;
    }

    $cmd = new TextPacket();
    $cmd->type = TextPacket::TYPE_CHAT;
    $cmd->source = 'Alice';
    $cmd->message = '/tp 10 80 -10';
    $client->sendGamePacket($cmd);

    $deadline = microtime(true) + 3.0;
    $sawTeleport = false;
    $packetY = null;
    $packetEid = null;
    while (microtime(true) < $deadline && !$sawTeleport) {
        $kernel->run(1);
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::MOVE_PLAYER_PACKET) {
                $mp = mpFields($buffer);
                if ($mp['mode'] === MovePlayerPacket::MODE_RESET && abs($mp['x'] - 10.0) < 0.01) {
                    $packetY = $mp['y'];
                    $packetEid = $mp['eid'];
                    $sawTeleport = true;
                }
            }
        }
        usleep(10000);
    }
    ok($sawTeleport, 'teleport MovePlayerPacket (MODE_RESET) sent');
    ok($packetEid === 0, 'teleport packet eid is 0 (protocol 84 self-entity)');
    ok(abs($pos->x - 10.0) < 0.01, 'position x updated to 10');
    ok(abs($pos->z - (-10.0)) < 0.01, 'position z updated to -10');
    ok($packetY !== null && $packetY >= 79.0 && $packetY <= 81.0, 'teleport packet y is ~80');
});

test('a /time command sets the world clock', function () use ($client, $kernel): void {
    $cmd = new TextPacket();
    $cmd->type = TextPacket::TYPE_CHAT;
    $cmd->source = 'Alice';
    $cmd->message = '/time set day';
    $client->sendGamePacket($cmd);

    $deadline = microtime(true) + 3.0;
    $sawTime = false;
    $sawReply = false;
    while (microtime(true) < $deadline && !($sawTime && $sawReply)) {
        $kernel->run(1);
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::SET_TIME_PACKET) {
                $t = stTime($buffer);
                if ($t >= 1000 && $t < 1015) {
                    $sawTime = true;
                }
            }
            if ($id === Info::TEXT_PACKET) {
                if (str_contains(textPacket($buffer)['message'] ?? '', 'Time set to 1000')) {
                    $sawReply = true;
                }
            }
        }
        usleep(10000);
    }
    ok($sawReply, '/time reply received');
    ok($sawTime, 'clients synced to the new dawn time');
});

test('a /weather command changes the world weather over the wire', function () use ($client, $kernel): void {
    // Pin clear first: the world may have naturally rolled into rain by this
    // point (the weather system re-rolls when a spell expires), which would
    // make /weather rain a no-op - no START_RAIN would go out and this test
    // would fail on a timing coin flip.
    $wc = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\WorldConfig::class);
    if ($wc instanceof \pocketmine\core\resource\WorldConfig) {
        $wc->weather = \pocketmine\core\system\WeatherSystem::CLEAR;
        $wc->weatherDuration = 600000;
        $wc->lightningTick = 0;
    }
    $kernel->run(3);
    $client->readGamePackets(); // drain the STOP_RAIN the clear pin produced

    $cmd = new TextPacket();
    $cmd->type = TextPacket::TYPE_CHAT;
    $cmd->source = 'Alice';
    $cmd->message = '/weather rain';
    $client->sendGamePacket($cmd);

    $deadline = microtime(true) + 3.0;
    $sawRain = false;
    $sawReply = false;
    while (microtime(true) < $deadline && !($sawRain && $sawReply)) {
        $kernel->run(1);
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::LEVEL_EVENT_PACKET) {
                if (leFields($buffer)['evid'] === \pocketmine\protocol\LevelEventPacket::EVENT_START_RAIN) {
                    $sawRain = true;
                }
            }
            if ($id === Info::TEXT_PACKET) {
                if (str_contains(textPacket($buffer)['message'] ?? '', 'Weather set to rain')) {
                    $sawReply = true;
                }
            }
        }
        usleep(10000);
    }
    ok($sawReply, '/weather reply received');
    ok($sawRain, 'clients received START_RAIN after /weather rain');

    // Back to clear: STOP_RAIN goes out.
    $cmd2 = new TextPacket();
    $cmd2->type = TextPacket::TYPE_CHAT;
    $cmd2->source = 'Alice';
    $cmd2->message = '/weather clear';
    $client->sendGamePacket($cmd2);

    $deadline = microtime(true) + 3.0;
    $sawClear = false;
    while (microtime(true) < $deadline && !$sawClear) {
        $kernel->run(1);
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::LEVEL_EVENT_PACKET
                && leFields($buffer)['evid'] === \pocketmine\protocol\LevelEventPacket::EVENT_STOP_RAIN) {
                $sawClear = true;
            }
        }
        usleep(10000);
    }
    ok($sawClear, 'clients received STOP_RAIN after /weather clear');
});

test('a /help command lists the commands', function () use ($client, $kernel): void {
    $cmd = new TextPacket();
    $cmd->type = TextPacket::TYPE_CHAT;
    $cmd->source = 'Alice';
    $cmd->message = '/help';
    $client->sendGamePacket($cmd);

    $deadline = microtime(true) + 3.0;
    $sawHelp = false;
    while (microtime(true) < $deadline && !$sawHelp) {
        $kernel->run(1);
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::TEXT_PACKET) {
                $tp = textPacket($buffer);
                if (str_contains($tp['message'] ?? '', 'gamemode')) {
                    $sawHelp = true;
                }
            }
        }
        usleep(10000);
    }
    ok($sawHelp, '/help lists the gamemode command');
});

test('a /kill command kills the player and respawn revives', function () use ($client, $kernel): void {
    $alice = null;
    foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
        if ($p['username'] === 'Alice') {
            $alice = $p;
            break;
        }
    }
    if ($alice === null) {
        ok(false, 'Alice is online');
        return;
    }
    $entity = $kernel->getWorld()->getEntity($alice['entityId']);
    $inv = $entity?->get(\pocketmine\core\component\InventoryComponent::class);
    $inv?->clear(); // nothing to drop into the world

    $cmd = new TextPacket();
    $cmd->type = TextPacket::TYPE_CHAT;
    $cmd->source = 'Alice';
    $cmd->message = '/kill';
    $client->sendGamePacket($cmd);

    $deadline = microtime(true) + 3.0;
    $sawDead = false;
    while (microtime(true) < $deadline && !$sawDead) {
        $kernel->run(1);
        $health = $entity?->get(\pocketmine\core\component\HealthComponent::class);
        if ($entity !== null && $entity->has(\pocketmine\core\component\tags\DeadTag::class)
            && $health !== null && $health->current === 0.0) {
            $sawDead = true;
        }
        usleep(10000);
    }
    ok($sawDead, 'Alice is dead after /kill');

    $client->readGamePackets(); // drain the death burst
    $respawn = new \pocketmine\protocol\RespawnPacket();
    $respawn->x = 0.0;
    $respawn->y = 0.0;
    $respawn->z = 0.0;
    $client->sendGamePacket($respawn);

    $deadline = microtime(true) + 5.0;
    $sawHealth = false;
    while (microtime(true) < $deadline && !$sawHealth) {
        $kernel->run(1);
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::SET_HEALTH_PACKET && shFields($buffer) === 20) {
                $sawHealth = true;
            }
        }
        usleep(10000);
    }
    $health = $entity?->get(\pocketmine\core\component\HealthComponent::class);
    ok($sawHealth && $health !== null && $health->current === 20.0, 'Alice revived with full health');
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

test('DIAG: second kill after respawn re-triggers death packets', function () use ($client, $kernel): void {
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
    $aliceId = $alice['entityId'];
    $aliceRef = \pocketmine\core\ecs\EntityRef::create($aliceId, $kernel->getWorld());

    // Second kill via the actual /kill chat wire.
    $cmd = new TextPacket();
    $cmd->type = TextPacket::TYPE_CHAT;
    $cmd->source = 'Alice';
    $cmd->message = '/kill';
    $client->sendGamePacket($cmd);

    $deadline = microtime(true) + 5.0;
    $sawDead = false;
    $dumps = [];
    $msgs = [];
    while (microtime(true) < $deadline && !$sawDead) {
        $kernel->run(1);
        $e = $kernel->getWorld()->getEntity($aliceId);
        $h = $e?->get(\pocketmine\core\component\HealthComponent::class);
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::SET_HEALTH_PACKET) {
                $dumps[] = 'SetHealth=' . shFields($buffer);
            } elseif ($id === Info::RESPAWN_PACKET) {
                $dumps[] = 'RespawnPacket';
            } elseif ($id === Info::PLAY_STATUS_PACKET) {
                $dumps[] = 'PlayStatus=' . psStatus($buffer);
            } elseif ($id === Info::ENTITY_EVENT_PACKET) {
                $dumps[] = 'EntityEvent';
            } elseif ($id === Info::MOVE_PLAYER_PACKET) {
                $dumps[] = 'Move';
            } elseif ($id === Info::TEXT_PACKET) {
                $msgs[] = textPacket($buffer)['message'];
            }
        }
        if ($e !== null && $e->has(\pocketmine\core\component\tags\DeadTag::class) && $h !== null && $h->current === 0.0) {
            $sawDead = true;
        }
        usleep(10000);
    }
    printf("DIAG second kill: dead=%s health=%s entityExists=%s\n", $sawDead ? 'yes' : 'no', $h?->current ?? 'null', $kernel->getWorld()->getEntity($aliceId) !== null ? 'yes' : 'no');
    printf("DIAG second kill msgs: %s\n", implode(', ', $msgs));
    printf("DIAG second kill packet stream: %s\n", implode(', ', array_slice($dumps, 0, 40)));
    ok($sawDead, 'Alice is dead after second /kill');
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
    ok($ritaSavedId !== null && file_exists($kernel->getDataPath() . 'worlds/world/players/' . $ritaSavedId . '.dat'), 'Rita data file written on disconnect');

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

// --- Chests (14.15) ---------------------------------------------------------

function copFields(string $buf): array {
    $s = new BinaryStream($buf, 1);
    return [
        'windowid' => $s->getByte(),
        'type' => $s->getByte(),
        'slots' => $s->getShort(),
        'x' => $s->getInt(),
        'y' => $s->getInt(),
        'z' => $s->getInt(),
        'entityId' => $s->getLong(),
    ];
}

function ccpFields(string $buf): array {
    $s = new BinaryStream($buf, 1);
    return ['windowid' => $s->getByte()];
}

/**
 * Find a free surface cell (air above real grass/dirt terrain) near the safe
 * spawn - shared by the chest/furnace block-placement helpers. Never stack on
 * a block left by a previous test.
 * @return array{0: int, 1: int, 2: int}
 */
function findFreeSurfaceCell(\pocketmine\Kernel $kernel): array {
    $store = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);
    $store = $store instanceof \pocketmine\core\resource\ChunkStore ? $store : null;
    if ($store === null) {
        throw new RuntimeException('no chunk store');
    }
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
                $top = $store->getHighestBlockAt($x, $z);
                $surface = $store->getBlock($x, $top, $z);
                if ($top < $sea || $surface === $water) {
                    continue; // underwater column
                }
                // Real terrain only (grass/dirt) - never a block a previous
                // test left behind.
                if ($surface !== 2 && $surface !== 3) {
                    continue;
                }
                if ($store->getBlock($x, $top + 1, $z) !== 0) {
                    continue; // cell above the surface is occupied
                }
                return [$x, $top + 1, $z];
            }
        }
    }
    throw new RuntimeException('no free surface cell for a test block');
}

/**
 * Drop a chest block into the world at the given position and teleport Alice
 * next to it so she can right-click it (the break/place tests teleport her
 * onto a surface block; the chest sits one cell above that surface).
 * @return array{0: int, 1: int, 2: int}
 */
function placeTestChest(\pocketmine\Kernel $kernel, FakeClient $client, int $entityId): array {
    $store = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);
    $store = $store instanceof \pocketmine\core\resource\ChunkStore ? $store : null;
    if ($store === null) {
        throw new RuntimeException('no chunk store');
    }
    [$cx, $cy, $cz] = findFreeSurfaceCell($kernel);
    $store->setBlock($cx, $cy, $cz, 54); // chest
    teleportEntityOnto($kernel, $client, $entityId, $cx, $cy, $cz);
    return [$cx, $cy, $cz];
}

/**
 * 14.16: drop a furnace block (unlit, id 61) into the world and teleport the
 * player next to it, mirroring placeTestChest.
 * @return array{0: int, 1: int, 2: int}
 */
function placeTestFurnace(\pocketmine\Kernel $kernel, FakeClient $client, int $entityId): array {
    $store = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);
    $store = $store instanceof \pocketmine\core\resource\ChunkStore ? $store : null;
    if ($store === null) {
        throw new RuntimeException('no chunk store');
    }
    [$fx, $fy, $fz] = findFreeSurfaceCell($kernel);
    $store->setBlock($fx, $fy, $fz, 61); // furnace
    teleportEntityOnto($kernel, $client, $entityId, $fx, $fy, $fz);
    return [$fx, $fy, $fz];
}

/**
 * 14.16: right-click a furnace and wait for the server's ContainerOpenPacket
 * (window 3, type 3). The furnace window only accepts writes for sessions
 * that have it open.
 * @return array<string, mixed>
 */
function openTestFurnace(\pocketmine\Kernel $kernel, FakeClient $client, int $fx, int $fy, int $fz): array {
    $use = new UseItemPacket();
    $use->x = $fx;
    $use->y = $fy;
    $use->z = $fz;
    $use->face = 1;
    $use->fx = 0.0;
    $use->fy = 0.0;
    $use->fz = 0.0;
    $use->posX = $fx + 0.5;
    $use->posY = $fy + 0.5;
    $use->posZ = $fz + 0.5;
    $use->slot = 0;
    $use->item = [0, 0, 0, null];
    $client->sendGamePacket($use);

    $deadline = microtime(true) + 3.0;
    while (microtime(true) < $deadline) {
        $kernel->run(1);
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::CONTAINER_OPEN_PACKET) {
                $fields = copFields($buffer);
                if (($fields['windowid'] ?? -1) === 3) {
                    return $fields;
                }
            }
        }
        usleep(10000);
    }
    throw new RuntimeException('furnace at (' . $fx . ', ' . $fy . ', ' . $fz . ') did not open');
}

/**
 * Right-click a chest and wait for the server's ContainerOpenPacket to
 * land. The server only accepts window-2 (chest) writes for sessions that
 * have the chest open, so tests must let the open round-trip first.
 *
 * @return array<string, mixed>
 */
function openTestChest(\pocketmine\Kernel $kernel, FakeClient $client, int $cx, int $cy, int $cz): array {
    $use = new UseItemPacket();
    $use->x = $cx;
    $use->y = $cy;
    $use->z = $cz;
    $use->face = 1;
    $use->fx = 0.0;
    $use->fy = 0.0;
    $use->fz = 0.0;
    $use->posX = $cx + 0.5;
    $use->posY = $cy + 0.5;
    $use->posZ = $cz + 0.5;
    $use->slot = 0;
    $use->item = [5, 32, 0, null];
    $client->sendGamePacket($use);

    $deadline = microtime(true) + 3.0;
    while (microtime(true) < $deadline) {
        $kernel->run(1);
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::CONTAINER_OPEN_PACKET) {
                $fields = copFields($buffer);
                if (($fields['windowid'] ?? -1) === 2) {
                    return $fields;
                }
            }
        }
        usleep(10000);
    }
    throw new RuntimeException('chest at (' . $cx . ', ' . $cy . ', ' . $cz . ') did not open');
}

// Earlier suite clients idle past the RakNet 10s transport timeout (the
// world-clock test notes this), so every chest test boots its own client.
function joinFreshClient(\pocketmine\Kernel $kernel, int $port, string $name, string $uuid): array {
    $c = new FakeClient($port);
    $c->handshake(fn() => $kernel->run(1));
    $c->connect(fn() => $kernel->run(1));
    $c->sendLogin($name, $uuid);
    $kernel->run(2);

    // Wait for the login burst to land (so the player is spawned and chunks
    // around the safe spawn are streamed before we place a chest there).
    $deadline = microtime(true) + 8.0;
    $spawned = false;
    while (microtime(true) < $deadline && !$spawned) {
        foreach ($c->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::PLAY_STATUS_PACKET && psStatus($buffer) === PlayStatusPacket::PLAYER_SPAWN) {
                $spawned = true;
            }
        }
        $kernel->run(1);
        usleep(10000);
    }
    if (!$spawned) {
        $c->close();
        throw new RuntimeException("$name did not spawn on login");
    }
    foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
        if ($p['username'] === $name) {
            return [$c, $p['entityId']];
        }
    }
    $c->close();
    throw new RuntimeException("$name not found after login");
}

test('right-clicking a chest opens a real container window (0x2a + contents)', function () use ($kernel, $port): void {
    [$chestClient, $eid] = joinFreshClient($kernel, $port, 'Chesty', 'e0000000-0000-0000-0000-0000000000c1');
    try {
        [$cx, $cy, $cz] = placeTestChest($kernel, $chestClient, $eid);

        // Right-click the chest: the client sends UseItem targeting the chest
        // block itself (not a face offset).
        $use = new UseItemPacket();
        $use->x = $cx;
        $use->y = $cy;
        $use->z = $cz;
        $use->face = 1;
        $use->fx = 0.0;
        $use->fy = 0.0;
        $use->fz = 0.0;
        $use->posX = $cx + 0.5;
        $use->posY = $cy + 0.5;
        $use->posZ = $cz + 0.5;
        $use->slot = 0;
        $use->item = [5, 32, 0, null];
        $chestClient->sendGamePacket($use);

        $deadline = microtime(true) + 3.0;
        $sawOpen = null;
        $sawContent = null;
        while (microtime(true) < $deadline && ($sawOpen === null || $sawContent === null)) {
            $kernel->run(1);
            foreach ($chestClient->readGamePackets() as [$id, $buffer]) {
                if ($id === Info::CONTAINER_OPEN_PACKET && $sawOpen === null) {
                    $sawOpen = copFields($buffer);
                }
                if ($id === Info::CONTAINER_SET_CONTENT_PACKET && $sawContent === null) {
                    $sawContent = cscFields($buffer);
                }
            }
            usleep(10000);
        }
        ok($sawOpen !== null, 'ContainerOpenPacket sent');
        if ($sawOpen !== null) {
            same(2, $sawOpen['windowid'], 'chest window id 2');
            same(0, $sawOpen['type'], 'chest window type 0');
            same(27, $sawOpen['slots'], '27 chest slots');
            same($cx, $sawOpen['x'], 'open x matches the chest block');
            same($cy, $sawOpen['y'], 'open y matches the chest block');
            same($cz, $sawOpen['z'], 'open z matches the chest block');
        }
        ok($sawContent !== null, 'chest contents sent after open');
        if ($sawContent !== null) {
            same(2, $sawContent['windowid'], 'contents ride window 2');
            same(27, count($sawContent['slots']), '27 content slots');
        }
    } finally {
        $chestClient->close();
    }
});

test('a chest-window move is validated and synced to every viewer', function () use ($kernel, $port): void {
    [$chestClient, $eid] = joinFreshClient($kernel, $port, 'Chesty2', 'e0000000-0000-0000-0000-0000000000c2');
    try {
        [$cx, $cy, $cz] = placeTestChest($kernel, $chestClient, $eid);

        // Open the chest first: window-2 writes are only accepted for
        // sessions that have the chest open.
        openTestChest($kernel, $chestClient, $cx, $cy, $cz);

        // The fresh player has the starter kit: planks in slot 0. First empty
        // slot 0 (releases 32 planks into move credit), then fill chest slot
        // 0 with 5 planks - a two-packet drag, exactly like window-0 moves.
        $empty = new ContainerSetSlotPacket();
        $empty->windowid = 0;
        $empty->slot = 0;
        $empty->hotbarSlot = 0;
        $empty->item = [0, 0, 0, null];
        $chestClient->sendGamePacket($empty);

        $fill = new ContainerSetSlotPacket();
        $fill->windowid = 2;
        $fill->slot = 0;
        $fill->hotbarSlot = 0;
        $fill->item = [5, 5, 0, null];
        $chestClient->sendGamePacket($fill);
        $kernel->run(2);

        $chestStore = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChestStore::class);
        if (!$chestStore instanceof \pocketmine\core\resource\ChestStore) {
            ok(false, 'chest store present');
            return;
        }
        $chestInv = $chestStore->get($cx, $cy, $cz);
        $item = $chestInv->get(0);
        ok($item !== null && $item->itemId === 5 && $item->count === 5, '5 planks landed in chest slot 0');

        // The changed chest slot is broadcast back to every viewer of that
        // chest (including the mover themselves).
        $deadline = microtime(true) + 3.0;
        $sawSlot = false;
        while (microtime(true) < $deadline && !$sawSlot) {
            foreach ($chestClient->readGamePackets() as [$id, $buffer]) {
                if ($id === Info::CONTAINER_SET_SLOT_PACKET) {
                    $css = cssFields($buffer);
                    if ($css['windowid'] === 2 && $css['slot'] === 0 && $css['item'][0] === 5) {
                        $sawSlot = true;
                    }
                }
            }
            $kernel->run(1);
            usleep(10000);
        }
        ok($sawSlot, 'chest slot change broadcast on window 2');
    } finally {
        $chestClient->close();
    }
});

test('a hostile chest-window claim without move credit is rejected', function () use ($kernel, $port): void {
    [$chestClient, $eid] = joinFreshClient($kernel, $port, 'Chesty3', 'e0000000-0000-0000-0000-0000000000c3');
    try {
        [$cx, $cy, $cz] = placeTestChest($kernel, $chestClient, $eid);
        $chestStore = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChestStore::class);
        $chestInv = $chestStore instanceof \pocketmine\core\resource\ChestStore ? $chestStore->get($cx, $cy, $cz) : null;
        if ($chestInv === null) {
            ok(false, 'chest store present');
            return;
        }

        // Claim 64 diamonds in chest slot 1 out of nowhere (no move credit
        // for diamonds was ever released).
        $claim = new ContainerSetSlotPacket();
        $claim->windowid = 2;
        $claim->slot = 1;
        $claim->hotbarSlot = 1;
        $claim->item = [264, 64, 0, null];
        $chestClient->sendGamePacket($claim);
        $kernel->run(2);

        ok($chestInv->get(1) === null, 'unbacked claim rejected - chest slot stays empty');
    } finally {
        $chestClient->close();
    }
});

test('closing the chest window clears it and mirrors the close', function () use ($kernel, $port): void {
    [$chestClient, $eid] = joinFreshClient($kernel, $port, 'Chesty4', 'e0000000-0000-0000-0000-0000000000c4');
    try {
        [$cx, $cy, $cz] = placeTestChest($kernel, $chestClient, $eid);

        // Open the chest first and wait for the open to round-trip: the
        // server only mirrors a close for an open container.
        openTestChest($kernel, $chestClient, $cx, $cy, $cz);

        $close = new ContainerClosePacket();
        $close->windowid = 2;
        $chestClient->sendGamePacket($close);
        $kernel->run(1);

        $deadline = microtime(true) + 3.0;
        $sawClose = false;
        while (microtime(true) < $deadline && !$sawClose) {
            foreach ($chestClient->readGamePackets() as [$id, $buffer]) {
                if ($id === Info::CONTAINER_CLOSE_PACKET) {
                    $ccp = ccpFields($buffer);
                    if ($ccp['windowid'] === 2) {
                        $sawClose = true;
                    }
                }
            }
            $kernel->run(1);
            usleep(10000);
        }
        ok($sawClose, 'server mirrors the chest close');
    } finally {
        $chestClient->close();
    }
});

test('breaking a chest spills its contents as item entities', function () use ($kernel, $port): void {
    [$chestClient, $eid] = joinFreshClient($kernel, $port, 'Chesty5', 'e0000000-0000-0000-0000-0000000000c5');
    try {
        [$cx, $cy, $cz] = placeTestChest($kernel, $chestClient, $eid);
        $chestStore = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChestStore::class);
        $chestInv = $chestStore instanceof \pocketmine\core\resource\ChestStore ? $chestStore->get($cx, $cy, $cz) : null;
        if ($chestInv === null) {
            ok(false, 'chest store present');
            return;
        }
        // Seed the chest with an item, then break the chest like the break
        // test (START_BREAK, hold, REMOVE_BLOCK).
        $chestInv->set(0, new \pocketmine\core\component\ItemStack(264, 0, 3));

        // Set the player to creative (gamemode 1) so the break is instant
        // on START_BREAK, bypassing the REMOVE_BLOCK confirm (which the fake
        // RakNet transport drops unpredictably).
        $entity = $kernel->getWorld()->getEntity($eid);
        $meta = $entity?->get(\pocketmine\core\component\MetadataComponent::class);
        if ($meta !== null) {
            $meta->set('gamemode', 1);
        }

        $action = new PlayerActionPacket();
        $action->eid = $eid;
        $action->action = PlayerActionPacket::ACTION_START_BREAK;
        $action->x = $cx;
        $action->y = $cy;
        $action->z = $cz;
        $action->face = 1;
        $chestClient->sendGamePacket($action);

        $deadline = microtime(true) + 3.0;
        $store = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);
        $store = $store instanceof \pocketmine\core\resource\ChunkStore ? $store : null;
        while (microtime(true) < $deadline && ($store === null || $store->getBlock($cx, $cy, $cz) !== 0)) {
            $kernel->run(1);
            usleep(10000);
        }
        ok($store !== null && $store->getBlock($cx, $cy, $cz) === 0, 'chest block is gone');
        ok(!($chestStore instanceof \pocketmine\core\resource\ChestStore && $chestStore->has($cx, $cy, $cz)), 'chest contents removed from the store');

        // The spilled diamond should exist as an item entity in the world.
        $found = false;
        $query = $kernel->getWorld()->query()
            ->with(\pocketmine\core\component\MetadataComponent::class)
            ->with(\pocketmine\core\component\PositionComponent::class)
            ->build();
        foreach ($query as $entity) {
            $meta = $entity->get(\pocketmine\core\component\MetadataComponent::class);
            $pos = $entity->get(\pocketmine\core\component\PositionComponent::class);
            if ($meta !== null && $pos !== null && $meta->get('entityType') === 'item'
                && abs($pos->x - $cx - 0.5) < 2 && abs($pos->z - $cz - 0.5) < 2) {
                $found = true;
            }
        }
        ok($found, 'a diamond item entity spawned at the broken chest');
    } finally {
        $chestClient->close();
    }
});

// --- Tile containers (14.27) ----------------------------------------------
test('two adjacent chests open as one 54-slot double window', function () use ($kernel, $port): void {
    [$chestClient, $eid] = joinFreshClient($kernel, $port, 'Chesty6', 'e0000000-0000-0000-0000-0000000000c6');
    try {
        [$cx, $cy, $cz] = placeTestChest($kernel, $chestClient, $eid);
        // Place a second chest one block east so the pair is detected at open
        // time (the right half is the second chest, slots 27-53).
        $store = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);
        $store = $store instanceof \pocketmine\core\resource\ChunkStore ? $store : null;
        if ($store === null) {
            ok(false, 'chunk store present');
            return;
        }
        $store->setBlock($cx + 1, $cy, $cz, 54);

        $use = new UseItemPacket();
        $use->x = $cx;
        $use->y = $cy;
        $use->z = $cz;
        $use->face = 1;
        $use->fx = 0.0;
        $use->fy = 0.0;
        $use->fz = 0.0;
        $use->posX = $cx + 0.5;
        $use->posY = $cy + 0.5;
        $use->posZ = $cz + 0.5;
        $use->slot = 0;
        $use->item = [5, 32, 0, null];
        $chestClient->sendGamePacket($use);

        $deadline = microtime(true) + 3.0;
        $sawOpen = null;
        $sawContent = null;
        while (microtime(true) < $deadline && ($sawOpen === null || $sawContent === null)) {
            $kernel->run(1);
            foreach ($chestClient->readGamePackets() as [$id, $buffer]) {
                if ($id === Info::CONTAINER_OPEN_PACKET && $sawOpen === null) {
                    $sawOpen = copFields($buffer);
                }
                if ($id === Info::CONTAINER_SET_CONTENT_PACKET && $sawContent === null) {
                    $sawContent = cscFields($buffer);
                }
            }
            usleep(10000);
        }
        ok($sawOpen !== null, 'double chest opens a window');
        if ($sawOpen !== null) {
            same(1, $sawOpen['type'], 'double chest window type 1');
            same(54, $sawOpen['slots'], '54 double-chest slots');
        }
        ok($sawContent !== null && count($sawContent['slots']) === 54, 'merged 54-slot contents sent');
    } finally {
        $chestClient->close();
    }
});

test('a double-chest move lands in the right physical half', function () use ($kernel, $port): void {
    [$chestClient, $eid] = joinFreshClient($kernel, $port, 'Chesty7', 'e0000000-0000-0000-0000-0000000000c7');
    try {
        [$cx, $cy, $cz] = placeTestChest($kernel, $chestClient, $eid);
        $store = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);
        $store = $store instanceof \pocketmine\core\resource\ChunkStore ? $store : null;
        if ($store === null) {
            ok(false, 'chunk store present');
            return;
        }
        $store->setBlock($cx + 1, $cy, $cz, 54); // right half

        // Open the pair (the helper waits for window 2 with 27 slots, so use
        // the direct right-click + wait loop like the test above instead).
        $use = new UseItemPacket();
        $use->x = $cx;
        $use->y = $cy;
        $use->z = $cz;
        $use->face = 1;
        $use->fx = 0.0;
        $use->fy = 0.0;
        $use->fz = 0.0;
        $use->posX = $cx + 0.5;
        $use->posY = $cy + 0.5;
        $use->posZ = $cz + 0.5;
        $use->slot = 0;
        $use->item = [5, 32, 0, null];
        $chestClient->sendGamePacket($use);
        $deadline = microtime(true) + 3.0;
        $opened = false;
        while (microtime(true) < $deadline && !$opened) {
            $kernel->run(1);
            foreach ($chestClient->readGamePackets() as [$id, $buffer]) {
                if ($id === Info::CONTAINER_OPEN_PACKET) {
                    $f = copFields($buffer);
                    if ($f['windowid'] === 2 && $f['slots'] === 54) {
                        $opened = true;
                    }
                }
            }
            usleep(10000);
        }
        ok($opened, 'double chest opened (54-slot window)');

        // Move 5 planks into window slot 30 = right half slot 3: empty the
        // player's slot 0 first (releases 32 planks into move credit), then
        // claim slot 30 of the double window.
        $empty = new ContainerSetSlotPacket();
        $empty->windowid = 0;
        $empty->slot = 0;
        $empty->hotbarSlot = 0;
        $empty->item = [0, 0, 0, null];
        $chestClient->sendGamePacket($empty);

        $fill = new ContainerSetSlotPacket();
        $fill->windowid = 2;
        $fill->slot = 30;
        $fill->hotbarSlot = 30;
        $fill->item = [5, 5, 0, null];
        $chestClient->sendGamePacket($fill);
        $kernel->run(2);

        $chestStore = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChestStore::class);
        if (!$chestStore instanceof \pocketmine\core\resource\ChestStore) {
            ok(false, 'chest store present');
            return;
        }
        // Slot 30 = right half (cx+1) local slot 3.
        $rightInv = $chestStore->get($cx + 1, $cy, $cz);
        $item = $rightInv->get(3);
        ok($item !== null && $item->itemId === 5 && $item->count === 5, '5 planks landed in the right half slot 3');
        ok($chestStore->get($cx, $cy, $cz)->get(30) === null, 'left half has no slot 30 (wrapped into the right half)');
    } finally {
        $chestClient->close();
    }
});

test('breaking one half of a double chest spills both halves', function () use ($kernel, $port): void {
    [$chestClient, $eid] = joinFreshClient($kernel, $port, 'Chesty8', 'e0000000-0000-0000-0000-0000000000c8');
    try {
        [$cx, $cy, $cz] = placeTestChest($kernel, $chestClient, $eid);
        $store = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);
        $store = $store instanceof \pocketmine\core\resource\ChunkStore ? $store : null;
        $chestStore = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChestStore::class);
        $chestStore = $chestStore instanceof \pocketmine\core\resource\ChestStore ? $chestStore : null;
        if ($store === null || $chestStore === null) {
            ok(false, 'chunk + chest stores present');
            return;
        }
        $store->setBlock($cx + 1, $cy, $cz, 54); // right half
        // Seed both halves, then break the left half.
        $chestStore->get($cx, $cy, $cz)->set(0, new \pocketmine\core\component\ItemStack(264, 0, 2));
        $chestStore->get($cx + 1, $cy, $cz)->set(0, new \pocketmine\core\component\ItemStack(265, 0, 3));

        // Creative instant break (like the existing chest-break test).
        $entity = $kernel->getWorld()->getEntity($eid);
        $meta = $entity?->get(\pocketmine\core\component\MetadataComponent::class);
        if ($meta !== null) {
            $meta->set('gamemode', 1);
        }
        $action = new PlayerActionPacket();
        $action->eid = $eid;
        $action->action = PlayerActionPacket::ACTION_START_BREAK;
        $action->x = $cx;
        $action->y = $cy;
        $action->z = $cz;
        $action->face = 1;
        $chestClient->sendGamePacket($action);

        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline && $store->getBlock($cx, $cy, $cz) !== 0) {
            $kernel->run(1);
            usleep(10000);
        }
        ok($store->getBlock($cx, $cy, $cz) === 0, 'left chest block is gone');
        ok(!$chestStore->has($cx, $cy, $cz), 'left half contents removed');
        ok(!$chestStore->has($cx + 1, $cy, $cz), 'right half contents removed too (whole pair spilled)');
    } finally {
        $chestClient->close();
    }
});

// --- Furnaces / smelting (14.16) -----------------------------------------
test('right-clicking a furnace opens a real container window (window 3, type 3)', function () use ($kernel, $port): void {
    [$furnaceClient, $eid] = joinFreshClient($kernel, $port, 'Furny', 'f0000000-0000-0000-0000-0000000000f1');
    try {
        [$fx, $fy, $fz] = placeTestFurnace($kernel, $furnaceClient, $eid);
        $open = openTestFurnace($kernel, $furnaceClient, $fx, $fy, $fz);
        same(3, $open['windowid'], 'furnace window id 3');
        same(3, $open['type'], 'furnace window type 3');
        same(3, $open['slots'], '3 furnace slots');
        same($fx, $open['x'], 'open x matches the furnace block');
        same($fy, $open['y'], 'open y matches the furnace block');
        same($fz, $open['z'], 'open z matches the furnace block');
    } finally {
        $furnaceClient->close();
    }
});

test('furnace window moves are validated with move credit and sync to the store', function () use ($kernel, $port): void {
    [$furnaceClient, $eid] = joinFreshClient($kernel, $port, 'Furny2', 'f0000000-0000-0000-0000-0000000000f2');
    try {
        [$fx, $fy, $fz] = placeTestFurnace($kernel, $furnaceClient, $eid);
        openTestFurnace($kernel, $furnaceClient, $fx, $fy, $fz);

        // The fresh player holds planks in slot 0: empty it (releases credit),
        // then fill furnace slot 0 (smelting input) with 1 iron ore and slot 1
        // (fuel) with 1 coal - a three-packet drag across the windows.
        $empty = new ContainerSetSlotPacket();
        $empty->windowid = 0;
        $empty->slot = 0;
        $empty->hotbarSlot = 0;
        $empty->item = [0, 0, 0, null];
        $furnaceClient->sendGamePacket($empty);

        // Seed iron ore + coal directly into the player's inventory (the
        // server-side authoritative slot), then release it with two more
        // empties so the furnace claims are fully backed by move credit.
        $entity = $kernel->getWorld()->getEntity($eid);
        $inv = $entity?->get(\pocketmine\core\component\InventoryComponent::class);
        if ($inv === null) {
            ok(false, 'player inventory present');
            return;
        }
        $inv->set(1, new \pocketmine\core\component\ItemStack(15, 0, 1)); // iron ore
        $inv->set(2, new \pocketmine\core\component\ItemStack(263, 0, 1)); // coal
        $empty1 = new ContainerSetSlotPacket();
        $empty1->windowid = 0;
        $empty1->slot = 1;
        $empty1->hotbarSlot = 1;
        $empty1->item = [0, 0, 0, null];
        $furnaceClient->sendGamePacket($empty1);
        $empty2 = new ContainerSetSlotPacket();
        $empty2->windowid = 0;
        $empty2->slot = 2;
        $empty2->hotbarSlot = 2;
        $empty2->item = [0, 0, 0, null];
        $furnaceClient->sendGamePacket($empty2);

        $fillOre = new ContainerSetSlotPacket();
        $fillOre->windowid = 3;
        $fillOre->slot = 0;
        $fillOre->hotbarSlot = 0;
        $fillOre->item = [15, 1, 0, null];
        $furnaceClient->sendGamePacket($fillOre);
        $fillFuel = new ContainerSetSlotPacket();
        $fillFuel->windowid = 3;
        $fillFuel->slot = 1;
        $fillFuel->hotbarSlot = 1;
        $fillFuel->item = [263, 1, 0, null];
        $furnaceClient->sendGamePacket($fillFuel);
        $kernel->run(3);

        $furnaceStore = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\FurnaceStore::class);
        if (!$furnaceStore instanceof \pocketmine\core\resource\FurnaceStore) {
            ok(false, 'furnace store present');
            return;
        }
        $state = $furnaceStore->get($fx, $fy, $fz);
        $ore = $state['inventory']->get(0);
        // The coal move landed and was immediately consumed when the furnace
        // lit on the next world tick (1 coal = 1600 burn ticks): the burn
        // state is the proof the fuel claim went through.
        ok($ore !== null && $ore->itemId === 15 && $ore->count === 1, 'iron ore landed in furnace slot 0');
        ok($state['burnTime'] > 0, 'coal was consumed to light the furnace (burning)');
        same(null, $state['inventory']->get(1), 'fuel slot empty after the coal was spent lighting');
    } finally {
        $furnaceClient->close();
    }
});

test('a lit furnace smelts ore and broadcasts the result slot', function () use ($kernel, $port): void {
    [$furnaceClient, $eid] = joinFreshClient($kernel, $port, 'Furny3', 'f0000000-0000-0000-0000-0000000000f3');
    try {
        [$fx, $fy, $fz] = placeTestFurnace($kernel, $furnaceClient, $eid);
        openTestFurnace($kernel, $furnaceClient, $fx, $fy, $fz);

        // Seed the furnace directly (the open window already got its contents;
        // the move-credit path is covered above): 1 iron ore + 1 coal. The
        // furnace lights on the next world tick and cooks for 200 ticks.
        $furnaceStore = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\FurnaceStore::class);
        if (!$furnaceStore instanceof \pocketmine\core\resource\FurnaceStore) {
            ok(false, 'furnace store present');
            return;
        }
        $state = $furnaceStore->get($fx, $fy, $fz);
        $state['inventory']->set(0, new \pocketmine\core\component\ItemStack(15, 0, 1)); // iron ore
        $state['inventory']->set(1, new \pocketmine\core\component\ItemStack(263, 0, 1)); // coal
        $furnaceStore->put($fx, $fy, $fz, $state);

        // Run enough ticks for the smelt to complete: lighting consumes the
        // coal and cookTime reaches 200 on the ~201st tick. The completed
        // smelt is pushed as a full window-3 content refresh (legacy
        // FurnaceInventory::onUpdate sends the whole window when the result
        // changes). The kernel paces ticks at 50ms, so the cook takes ~10s of
        // real time - the RakNet 10s idle timeout would drop a silent client,
        // so the fake client reports its position every iteration like a real
        // player to keep the session alive.
        // The cook needs ~201 ticks; at 50ms/tick that is ~10s, but the
        // suite machine runs slower under the chunk load the infinite-world
        // streaming adds (boundary re-queues keep more chunks resident). Keep
        // the deadline generous so a correctly-cooking furnace is never
        // flagged for mere tick-rate jitter.
        $deadline = microtime(true) + 45.0;
        $sawIngot = false;
        $sawLit = false;
        while (microtime(true) < $deadline && (!$sawIngot || !$sawLit)) {
            $kernel->run(10);
            // Client keepalive: re-send the last known position.
            $ka = new MovePlayerPacket();
            $ka->eid = $eid;
            $ka->x = $fx + 0.5;
            $ka->y = $fy + 1;
            $ka->z = $fz + 0.5;
            $ka->yaw = 0.0;
            $ka->bodyYaw = 0.0;
            $ka->pitch = 0.0;
            $ka->mode = MovePlayerPacket::MODE_NORMAL;
            $ka->onGround = true;
            $furnaceClient->sendGamePacket($ka);
            foreach ($furnaceClient->readGamePackets() as [$id, $buffer]) {
                if ($id === Info::CONTAINER_SET_CONTENT_PACKET) {
                    $csc = cscFields($buffer);
                    if ($csc['windowid'] === 3 && ($csc['slots'][2][0] ?? 0) === 265) {
                        $sawIngot = true;
                    }
                }
                if ($id === Info::UPDATE_BLOCK_PACKET) {
                    $ub = ubFields($buffer);
                    if ($ub['x'] === $fx && $ub['y'] === $fy && $ub['z'] === $fz && $ub['blockId'] === 62) {
                        $sawLit = true;
                    }
                }
            }
            usleep(10000);
        }
        ok($sawLit, 'lit furnace block state (62) broadcast to the client');
        ok($sawIngot, 'smelted iron ingot broadcast on furnace window 3 (content refresh)');
    } finally {
        $furnaceClient->close();
    }
});

// --- Bows / arrows (14.17) ------------------------------------------------
test('a held bow charges on use and fires an arrow on ACTION_RELEASE_ITEM', function () use ($kernel, $port): void {
    // Earlier suite clients idle past the RakNet 10s transport timeout, so
    // boot a fresh one (same pattern as the chest/furnace tests).
    [$bowClient, $eid] = joinFreshClient($kernel, $port, 'Archer', 'a0000000-0000-0000-0000-0000000000a1');
    try {
        // A real client tunes its view distance; request a small one so the
        // test client's UDP receive buffer is not flooded with megabytes of
        // chunk data (which would drop the arrow's AddEntityPacket below the
        // OS buffer watermark before the poll loop can read it).
        $radiusReq = new RequestChunkRadiusPacket();
        $radiusReq->radius = 2;
        $bowClient->sendGamePacket($radiusReq);
        $kernel->run(1);
        $entity = $kernel->getWorld()->getEntity($eid);
        $inv = $entity?->get(\pocketmine\core\component\InventoryComponent::class);
        if ($inv === null) {
            ok(false, 'archer inventory present');
            return;
        }
        // Bow (261) in the held slot, arrows (262) in slot 1. Fresh bow: meta 0.
        $inv->set(0, new \pocketmine\core\component\ItemStack(261, 0, 1));
        $inv->set(1, new \pocketmine\core\component\ItemStack(262, 0, 3));
        $inv->setHeldSlot(0);

        // Aim flat +Z (yaw 0, pitch 0) so the arrow flies away from the
        // player; point the use packet at an air cell above her so the
        // chest/furnace intercept does not trigger.
        $rot = $entity?->get(\pocketmine\core\component\RotationComponent::class);
        if ($rot) {
            $rot->yaw = 0.0;
            $rot->pitch = 0.0;
        }
        $archer = null;
        foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
            if ($p['username'] === 'Archer') {
                $archer = $p;
            }
        }
        if ($archer === null) {
            ok(false, 'archer position known');
            return;
        }

        // USE_ITEM with the bow starts the draw (legacy startAction).
        $use = new UseItemPacket();
        $use->x = (int)floor($archer['x']);
        $use->y = (int)floor($archer['y']);
        $use->z = (int)floor($archer['z']);
        $use->face = 1; // up
        $use->fx = 0.0;
        $use->fy = 1.0;
        $use->fz = 0.0;
        $use->posX = $archer['x'];
        $use->posY = $archer['y'];
        $use->posZ = $archer['z'];
        $use->slot = 0;
        $use->item = [261, 0, 1, null];
        $bowClient->sendGamePacket($use);
        $kernel->run(2);

        // Charge for 30 ticks (> full draw at 20). A real client keeps
        // streaming movement while aiming, so send keepalive MovePlayer
        // packets or the RakNet 10s idle timeout drops the session before
        // the release arrives.
        $keep = new MovePlayerPacket();
        $keep->eid = $eid;
        $keep->x = $archer['x'];
        $keep->y = $archer['y'];
        $keep->z = $archer['z'];
        $keep->yaw = 0.0;
        $keep->bodyYaw = 0.0;
        $keep->pitch = 0.0;
        $keep->mode = MovePlayerPacket::MODE_NORMAL;
        $keep->onGround = true;
        for ($i = 0; $i < 30; $i++) {
            if ($i % 5 === 0) {
                $bowClient->sendGamePacket($keep);
            }
            // Drain the client's socket so the chunk stream (even at the
            // reduced radius) cannot overflow the OS receive buffer and drop
            // the arrow's AddEntityPacket before the poll loop reads it.
            $bowClient->readGamePackets();
            $kernel->run(1);
        }

        // Release: ACTION_RELEASE_ITEM fires the arrow.
        $release = new PlayerActionPacket();
        $release->eid = 0;
        $release->action = PlayerActionPacket::ACTION_RELEASE_ITEM;
        $release->x = 0;
        $release->y = 0;
        $release->z = 0;
        $release->face = 0;
        $bowClient->sendGamePacket($release);

        // Poll until the arrow entity appears (spawn is same-tick; broadcast
        // on the next entity sync) and the inventory reflects the consumed
        // arrow. Send keepalive movement so the session survives the cook-adj
        // idle (the release packet itself counts as traffic).
        $deadline = microtime(true) + 5.0;
        $arrowEid = null;
        $sawArrowAdd = false;
        $invAfter = null;
        while (microtime(true) < $deadline) {
            foreach ($kernel->getWorld()->getEntities() as $id => $e) {
                $m = $e->get(\pocketmine\core\component\MetadataComponent::class);
                if ($m?->get('projectileType') === 'Arrow' && $arrowEid === null) {
                    $arrowEid = $id;
                }
            }
            foreach ($bowClient->readGamePackets() as [$id, $buffer]) {
                if ($id === Info::ADD_ENTITY_PACKET) {
                    $ae = aeFields($buffer);
                    if ($ae['type'] === 80) { // legacy Arrow::NETWORK_ID
                        $sawArrowAdd = true;
                    }
                }
            }
            $invAfter = $kernel->getWorld()->getEntity($eid)?->get(\pocketmine\core\component\InventoryComponent::class);
            if ($arrowEid !== null && $sawArrowAdd && $invAfter !== null && ($invAfter->get(1)?->count ?? 0) <= 2) {
                break;
            }
            $kernel->run(1);
        }

        // In-flight rendering (14.18): while the arrow flies, the server
        // follows it with MoveEntityPacket every tick (position changes), so
        // the client sees it travel instead of freezing at the spawn point.
        $moveDeadline = microtime(true) + 4.0;
        $sawArrowMove = false;
        while (microtime(true) < $moveDeadline && !$sawArrowMove) {
            foreach ($bowClient->readGamePackets() as [$id, $buffer]) {
                if ($id === Info::MOVE_ENTITY_PACKET && strlen($buffer) >= 24) {
                    $ms = new BinaryStream($buffer, 1);
                    if ($arrowEid !== null && $ms->getLong() === $arrowEid) {
                        $sawArrowMove = true;
                        break;
                    }
                }
            }
            $kernel->run(1);
        }

        ok($arrowEid !== null, 'arrow entity spawned server-side');
        ok($sawArrowAdd, 'client received AddEntityPacket for the arrow (type 80)');
        ok($sawArrowMove, 'client received MoveEntityPacket for the flying arrow (in-flight rendering)');
        ok($invAfter !== null && ($invAfter->get(1)?->count ?? 0) === 2, 'one arrow consumed from the inventory (3 -> 2)');
        // Bow wore one durability in survival.
        $bowAfter = $invAfter?->get(0);
        ok($bowAfter !== null && $bowAfter->itemId === 261 && $bowAfter->meta === 1, 'bow durability incremented to 1');
    } finally {
        $bowClient->close();
    }
});

// --- 14.20 multi-world: switching worlds re-streams the new world's chunks --
test('switchWorld moves the session to a new world and streams its chunks', function () use ($kernel, $port): void {
    // A second world with a fixed seed, generated through the Server API.
    $server = \pocketmine\api\server\Server::getInstance();
    $world2 = $server->generateWorld('switch_world', 777);
    ok($world2 !== null, 'second world generated');

    $sw = new FakeClient($port);
    $sw->handshake(fn() => $kernel->run(1));
    $sw->connect(fn() => $kernel->run(1));
    $sw->sendLogin('Switcher', 'aaaa1111-2222-3333-4444-555555555555');

    // Wait for the initial (default world) chunk stream to start.
    $deadline = microtime(true) + 8.0;
    $sawAnyChunk = false;
    while (microtime(true) < $deadline && !$sawAnyChunk) {
        foreach ($sw->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::FULL_CHUNK_DATA_PACKET) {
                $sawAnyChunk = true;
                break;
            }
        }
        $kernel->run(1);
    }
    ok($sawAnyChunk, 'player received default-world chunks before the switch');

    // Find the player's entity id through the session service.
    $entityId = null;
    foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
        if ($p['username'] === 'Switcher') {
            $entityId = $p['entityId'];
            break;
        }
    }
    ok($entityId !== null, 'switcher session found');

    // Switch to the second world: the session must re-stream that world's
    // terrain (different seed => different chunk bytes) and receive its time.
    $ok = false;
    if ($entityId !== null) {
        $ok = $kernel->getNetworkSessionService()->switchWorld($entityId, $world2->getWorldId());
    }
    ok($ok, 'switchWorld accepted');

    $deadline = microtime(true) + 8.0;
    $sawNewChunk = false;
    $sawTime = false;
    while (microtime(true) < $deadline && (!$sawNewChunk || !$sawTime)) {
        foreach ($sw->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::FULL_CHUNK_DATA_PACKET) {
                $fc = fcFields($buffer);
                // The switch teleports the player to the new world's spawn,
                // so the streamed chunks should center on the new world's
                // chunk (0,0) - the same coordinate, but the payload comes
                // from the second world's store (777 seed).
                if ($fc['x'] === 0 && $fc['z'] === 0) {
                    $sawNewChunk = true;
                }
            }
            if ($id === Info::SET_TIME_PACKET) {
                $sawTime = true;
            }
        }
        $kernel->run(1);
    }
    ok($sawNewChunk, 'new-world chunk (0,0) streamed after the switch');
    ok($sawTime, 'SetTimePacket sent after the switch');

    // The player entity now belongs to world 2.
    $swEntity = $kernel->getWorld()->getEntity($entityId ?? -1);
    $wc = $swEntity?->get(\pocketmine\core\component\WorldComponent::class);
    ok($wc instanceof \pocketmine\core\component\WorldComponent && $wc->id === $world2->getWorldId(), 'player WorldComponent now points at world 2');
    $sw->close();
});

// --- Infinite world: the chunk stream follows the player -------------------
// The world is unbounded; the bug was that chunks were only queued once (on
// login / radius change / world switch), so walking past the initial window
// hit an invisible wall. handleMove re-queues when the player crosses a chunk
// boundary, so the stream must keep growing as the player moves.
//
// Placed at the end (kernel idle) and using a FRESH client so no session
// state or lingering chunk queue leaks into the tests that follow.
test('chunk stream follows the player across chunk boundaries', function () use ($kernel, $port): void {
    $streamer = new FakeClient($port);
    $streamer->handshake(fn() => $kernel->run(1));
    $streamer->connect(fn() => $kernel->run(1));
    $streamer->sendLogin('Streamer', 'b0000000-0000-0000-0000-0000000000b1');

    // Wait for the login chunk stream to start (the switchWorld test uses
    // this same inline pattern at this position in the file).
    $deadline = microtime(true) + 10.0;
    $sawChunk = false;
    $eid = null;
    while (microtime(true) < $deadline && (!$sawChunk || $eid === null)) {
        foreach ($streamer->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::FULL_CHUNK_DATA_PACKET) {
                $sawChunk = true;
            }
        }
        $kernel->run(1);
        foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
            if ($p['username'] === 'Streamer') {
                $eid = $p['entityId'];
            }
        }
    }
    ok($sawChunk, 'player received login chunks');
    ok($eid !== null, 'streamer session found');

    // Small radius (3 => 49 chunks) so the moved window is cheap and the
    // growth signal is unambiguous.
    $radiusBody = chr(Info::REQUEST_CHUNK_RADIUS_PACKET) . pack('N', 3);
    $streamer->sendRawBuffer($radiusBody);
    $kernel->run(1);

    $net = $kernel->getNetworkSessionService();
    $baseline = 0;
    foreach ($net->getOnlinePlayers() as $p) {
        if ($p['username'] === 'Streamer') {
            $baseline = (int)$p['chunksSent'];
        }
    }
    ok($baseline > 0, "chunks already streamed before the move ($baseline)");

    // Jump ~10 chunk columns away - beyond the initially streamed window. A
    // server teleport (/tp) must re-queue the new area, exactly like walking
    // across a chunk boundary does.
    $kernel->getNetworkSessionService()->sendTeleportTo($eid, 168.5, 80.0, 168.5);

    $after = $baseline;
    $deadline = microtime(true) + 8.0;
    while (microtime(true) < $deadline) {
        $kernel->run(1);
        $after = $baseline; // reset: only a live session can raise it
        foreach ($net->getOnlinePlayers() as $p) {
            if ($p['username'] === 'Streamer') {
                $after = (int)$p['chunksSent'];
            }
        }
        if ($after > $baseline) {
            break;
        }
    }
    ok($after > $baseline, "chunk stream followed the player: $baseline -> $after chunks");
    $streamer->close();
});

test('weather transitions and lightning strike hit the wire', function () use ($kernel, $port): void {
    $wc = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\WorldConfig::class);
    ok($wc instanceof \pocketmine\core\resource\WorldConfig, 'WorldConfig resource present');
    if (!$wc instanceof \pocketmine\core\resource\WorldConfig) {
        return;
    }
    // The earlier tests have ticked the world for minutes of game time, so the
    // weather may be mid-spell. Pin a long clear spell so the login-burst
    // assertions below are deterministic (the system only rolls a new spell
    // when the current one expires).
    $wc->weather = \pocketmine\core\system\WeatherSystem::CLEAR;
    $wc->weatherDuration = 600000;
    $wc->lightningTick = 0;

    $weatherClient = new FakeClient($port);
    $weatherClient->handshake(fn() => $kernel->run(1));
    $weatherClient->connect(fn() => $kernel->run(1));
    $weatherClient->sendLogin('Weather', 'c0000000-0000-0000-0000-0000000000c1');

    // The login burst must carry the current weather state (two LevelEvent
    // packets - rain + thunder - both STOP when the world starts clear).
    $deadline = microtime(true) + 10.0;
    $sawChunk = false;
    $loginWeather = [];
    while (microtime(true) < $deadline && (!$sawChunk || count($loginWeather) < 2)) {
        foreach ($weatherClient->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::FULL_CHUNK_DATA_PACKET) {
                $sawChunk = true;
            }
            if ($id === Info::LEVEL_EVENT_PACKET) {
                $loginWeather[] = leFields($buffer)['evid'];
            }
        }
        $kernel->run(1);
    }
    ok($sawChunk, 'weather client received login chunks');
    ok(in_array(\pocketmine\protocol\LevelEventPacket::EVENT_STOP_RAIN, $loginWeather, true), 'login burst stopped rain (clear start)');
    ok(in_array(\pocketmine\protocol\LevelEventPacket::EVENT_STOP_THUNDER, $loginWeather, true), 'login burst stopped thunder (clear start)');

    // A server-side rain transition must push START_RAIN over the wire.
    $wc->weather = \pocketmine\core\system\WeatherSystem::RAIN;
    $wc->weatherDuration = 600000; // long spell: no mid-test roll back to clear
    $deadline = microtime(true) + 5.0;
    $sawRain = false;
    while (microtime(true) < $deadline && !$sawRain) {
        foreach ($weatherClient->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::LEVEL_EVENT_PACKET
                && leFields($buffer)['evid'] === \pocketmine\protocol\LevelEventPacket::EVENT_START_RAIN) {
                $sawRain = true;
            }
        }
        $kernel->run(1);
    }
    ok($sawRain, 'START_RAIN pushed on the rain transition');

    // Escalating to a storm pushes START_THUNDER (rain stays on).
    $wc->weather = \pocketmine\core\system\WeatherSystem::RAINY_THUNDER;
    $deadline = microtime(true) + 5.0;
    $sawThunder = false;
    while (microtime(true) < $deadline && !$sawThunder) {
        foreach ($weatherClient->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::LEVEL_EVENT_PACKET
                && leFields($buffer)['evid'] === \pocketmine\protocol\LevelEventPacket::EVENT_START_THUNDER) {
                $sawThunder = true;
            }
        }
        $kernel->run(1);
    }
    ok($sawThunder, 'START_THUNDER pushed when the storm starts');

    // One tick before the strike interval: the next world tick crosses 200
    // and the network layer must broadcast a lightning bolt (AddEntityPacket
    // type 93) to everyone in the world.
    $wc->lightningTick = \pocketmine\core\system\WeatherSystem::LIGHTNING_INTERVAL - 1;
    $deadline = microtime(true) + 5.0;
    $sawBolt = false;
    while (microtime(true) < $deadline && !$sawBolt) {
        foreach ($weatherClient->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::ADD_ENTITY_PACKET) {
                $ae = aeFields($buffer);
                if ($ae['type'] === 93) {
                    $sawBolt = true;
                }
            }
        }
        $kernel->run(1);
    }
    ok($sawBolt, 'lightning bolt (AddEntityPacket type 93) broadcast during the storm');
    $weatherClient->close();
});

test('bans and the whitelist reject logins with a DisconnectPacket', function () use ($kernel, $port, $lists): void {
    $lists = $lists ?? $kernel->getResourceRegistry()->get(\pocketmine\core\resource\PlayerListManager::class);
    if (!$lists instanceof \pocketmine\core\resource\PlayerListManager) {
        ok(false, 'PlayerListManager available');
        return;
    }
    $serverCfg = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ServerConfig::class);

    $tryLogin = static function (string $name, string $uuid) use ($kernel, $port): array {
        $c = new FakeClient($port);
        $c->handshake(fn() => $kernel->run(1));
        $c->connect(fn() => $kernel->run(1));
        $c->sendLogin($name, $uuid);
        $deadline = microtime(true) + 8.0;
        $ids = [];
        while (microtime(true) < $deadline) {
            foreach ($c->readGamePackets() as [$id, $buffer]) {
                $ids[$id] = true;
            }
            $kernel->run(1);
            if (isset($ids[Info::DISCONNECT_PACKET])) {
                break;
            }
        }
        $c->close();
        return array_keys($ids);
    };

    // Name ban: login must be rejected with a DisconnectPacket and never
    // reach the login-success burst.
    $lists->ban('Nono');
    $ids = $tryLogin('Nono', 'abababab-abab-abab-abab-abababababab');
    ok(in_array(Info::DISCONNECT_PACKET, $ids, true), 'banned name gets a DisconnectPacket');
    ok(!in_array(Info::START_GAME_PACKET, $ids, true), 'banned login never reaches StartGame');
    $lists->pardon('Nono');

    // Whitelist on: only whitelisted names may join.
    if ($serverCfg instanceof \pocketmine\core\resource\ServerConfig) {
        $serverCfg->whiteList = true;
        $lists->addWhitelist('alice');
        $ids = $tryLogin('Nono', 'abababab-abab-abab-abab-abababababac');
        ok(in_array(Info::DISCONNECT_PACKET, $ids, true), 'non-whitelisted name rejected while whitelist is on');
        ok(!in_array(Info::START_GAME_PACKET, $ids, true), 'rejected login never reaches StartGame');
        $serverCfg->whiteList = false;
        $lists->removeWhitelist('alice');
    }
});

test('/kick disconnects an online player immediately with a DisconnectPacket', function () use ($kernel, $port): void {
    [$kickClient] = joinFreshClient($kernel, $port, 'Kicky', 'e0000000-0000-0000-0000-0000000000d1');
    $lists = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\PlayerListManager::class);
    try {
        $ok = $kernel->getCommandPort()->execute(new \pocketmine\api\command\ConsoleCommandSender(), 'kick Kicky');
        ok($ok, '/kick executes');

        $gotDisconnect = false;
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            foreach ($kickClient->readGamePackets() as [$id, $buffer]) {
                if ($id === Info::DISCONNECT_PACKET) {
                    $gotDisconnect = true;
                }
            }
            $kernel->run(1);
            $online = array_column($kernel->getNetworkSessionService()->getOnlinePlayers(), 'username');
            if ($gotDisconnect && !in_array('Kicky', $online, true)) {
                break;
            }
            usleep(10000);
        }
        ok($gotDisconnect, 'kicked client received a DisconnectPacket immediately');
        $online = array_column($kernel->getNetworkSessionService()->getOnlinePlayers(), 'username');
        ok(!in_array('Kicky', $online, true), 'kicked player is offline right away');
        // Kick is not a ban: the player is never added to the banned list.
        if ($lists instanceof \pocketmine\core\resource\PlayerListManager) {
            ok(!$lists->isBanned('Kicky'), '/kick does not write a ban');
        }
    } finally {
        $kickClient->close();
    }
});

test('/ban kicks an online player immediately (DisconnectPacket + offline + banned)', function () use ($kernel, $port): void {
    [$banClient] = joinFreshClient($kernel, $port, 'Banee', 'e0000000-0000-0000-0000-0000000000d2');
    $lists = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\PlayerListManager::class);
    try {
        $ok = $kernel->getCommandPort()->execute(new \pocketmine\api\command\ConsoleCommandSender(), 'ban Banee');
        ok($ok, '/ban executes');

        $gotDisconnect = false;
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            foreach ($banClient->readGamePackets() as [$id, $buffer]) {
                if ($id === Info::DISCONNECT_PACKET) {
                    $gotDisconnect = true;
                }
            }
            $kernel->run(1);
            $online = array_column($kernel->getNetworkSessionService()->getOnlinePlayers(), 'username');
            if ($gotDisconnect && !in_array('Banee', $online, true)) {
                break;
            }
            usleep(10000);
        }
        ok($gotDisconnect, 'banned client received a DisconnectPacket immediately');
        $online = array_column($kernel->getNetworkSessionService()->getOnlinePlayers(), 'username');
        ok(!in_array('Banee', $online, true), 'banned player is offline right away');
        if ($lists instanceof \pocketmine\core\resource\PlayerListManager) {
            ok($lists->isBanned('Banee'), '/ban recorded the ban');
        }
    } finally {
        if ($lists instanceof \pocketmine\core\resource\PlayerListManager) {
            $lists->pardon('Banee');
        }
        $banClient->close();
    }
});

test('anti-cheat can be disabled via khronos.json config', function () use ($kernel, $port): void {
    $cfg = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\KhronosConfig::class);
    if (!$cfg instanceof \pocketmine\core\resource\KhronosConfig) {
        ok(false, 'KhronosConfig registered');
        return;
    }
    $prev = $cfg->antiCheatEnabled;
    $cfg->antiCheatEnabled = false;
    [$daisy] = joinFreshClient($kernel, $port, 'Daisy', 'e0000000-0000-0000-0000-0000000000d3');
    try {
        $before = null;
        foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
            if ($p['username'] === 'Daisy') {
                $before = $p;
            }
        }
        if ($before === null) {
            ok(false, 'Daisy is online');
            return;
        }

        // A 100-block teleport that the anti-cheat would rubber-band.
        $hack = new MovePlayerPacket();
        $hack->eid = 0;
        $hack->x = $before['x'] + 100.0;
        $hack->y = $before['y'];
        $hack->z = $before['z'];
        $hack->yaw = 0.0;
        $hack->bodyYaw = 0.0;
        $hack->pitch = 0.0;
        $hack->mode = MovePlayerPacket::MODE_NORMAL;
        $hack->onGround = true;
        $daisy->sendGamePacket($hack);
        $kernel->run(2);

        $applied = false;
        foreach ($kernel->getNetworkSessionService()->getOnlinePlayers() as $p) {
            if ($p['username'] === 'Daisy') {
                near($before['x'] + 100.0, $p['x'], 1e-6, 'overspeed move applied with anti-cheat off');
                $applied = true;
            }
        }
        ok($applied, 'moved position read back');
    } finally {
        $cfg->antiCheatEnabled = $prev;
        $daisy->close();
    }
});

// --- Nether (14.32) ----------------------------------------------------------

/** Build a 4-wide x 5-tall obsidian portal frame in the default world. */
function buildPortalFrame(\pocketmine\Kernel $kernel, int $ox, int $oy, int $oz): ?\pocketmine\core\resource\ChunkStore {
    $store = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ChunkStore::class);
    $store = $store instanceof \pocketmine\core\resource\ChunkStore ? $store : null;
    if ($store === null) {
        return null;
    }
    for ($i = 0; $i < 4; $i++) {
        $store->setBlock($ox + $i, $oy, $oz, 49, 0); // bottom row
        $store->setBlock($ox + $i, $oy + 4, $oz, 49, 0); // top row
    }
    for ($i = 1; $i < 4; $i++) {
        $store->setBlock($ox, $oy + $i, $oz, 49, 0);
        $store->setBlock($ox + 3, $oy + $i, $oz, 49, 0);
    }
    return $store;
}

/**
 * Late-suite clients (Alice) idle past the RakNet 10s transport timeout
 * during the long disconnect tests, so the portal tests join their own
 * fresh client like every other late test (joinFreshClient).
 */

test('flint & steel lights a complete obsidian portal frame', function () use ($kernel, $port): void {
    [$pc, $peteId] = joinFreshClient($kernel, $port, 'Pete', 'e0000000-0000-0000-0000-0000000000e1');
    try {
        [$bx, $by, $bz] = findSurfaceBlockNearSpawn($kernel);
        $ox = $bx + 8;
        $oy = $by + 2;
        $oz = $bz;
        $store = buildPortalFrame($kernel, $ox, $oy, $oz);
        ok($store !== null, 'portal frame built');
        if ($store === null) {
            return;
        }
        $inv = $kernel->getWorld()->getEntity($peteId)?->get(\pocketmine\core\component\InventoryComponent::class);
        ok($inv !== null, 'Pete inventory present');
        if ($inv === null) {
            return;
        }
        $inv->set(0, new \pocketmine\core\component\ItemStack(259, 0, 1)); // flint & steel
        $inv->setHeldSlot(0);

        // Click the bottom obsidian of the frame with the flint & steel.
        $use = new UseItemPacket();
        $use->x = $ox + 1;
        $use->y = $oy;
        $use->z = $oz;
        $use->face = 1;
        $use->fx = 0.5;
        $use->fy = 1.0;
        $use->fz = 0.5;
        $use->posX = $ox + 1.5;
        $use->posY = $oy + 1.0;
        $use->posZ = $oz + 0.5;
        $use->slot = 0;
        $use->item = [259, 1, 0, null];
        $pc->sendGamePacket($use);
        $kernel->run(2);

        ok($store->getBlock($ox + 1, $oy + 1, $oz) === 90, 'portal lit inside the frame (90)');
        ok($store->getBlock($ox + 2, $oy + 3, $oz) === 90, 'portal interior fully filled');
        ok($store->getBlock($ox + 1, $oy + 4, $oz) === 49, 'frame top row intact (49)');

        // Clean up the frame so later tests see an unchanged world.
        for ($i = 0; $i < 4; $i++) {
            for ($y = $oy; $y <= $oy + 4; $y++) {
                $store->setBlock($ox + $i, $y, $oz, 0, 0);
            }
        }
    } finally {
        $pc->close();
    }
});

test('standing in a portal crosses to the nether and back via ChangeDimensionPacket', function () use ($kernel, $port): void {
    // A distinct name: the previous test's Pete session lingers until the
    // RakNet timeout, so reusing the name would suffix it to 'Pete1'.
    [$pc, $peteId] = joinFreshClient($kernel, $port, 'Pete2', 'e0000000-0000-0000-0000-0000000000e2');
    try {
        $nether = $kernel->getWorldRegistry()->getWorldIdByName('nether');
        ok($nether === null, 'nether world does not exist before first use');

        [$bx, $by, $bz] = findSurfaceBlockNearSpawn($kernel);
        $ox = $bx + 8;
        $oy = $by + 2;
        $oz = $bz;
        $store = buildPortalFrame($kernel, $ox, $oy, $oz);
        ok($store !== null, 'portal frame built');
        if ($store === null) {
            return;
        }
        $inv = $kernel->getWorld()->getEntity($peteId)?->get(\pocketmine\core\component\InventoryComponent::class);
        ok($inv !== null, 'Pete inventory present');
        if ($inv === null) {
            return;
        }
        $inv->set(0, new \pocketmine\core\component\ItemStack(259, 0, 1));
        $inv->setHeldSlot(0);
        $use = new UseItemPacket();
        $use->x = $ox + 1;
        $use->y = $oy;
        $use->z = $oz;
        $use->face = 1;
        $use->fx = 0.5;
        $use->fy = 1.0;
        $use->fz = 0.5;
        $use->posX = $ox + 1.5;
        $use->posY = $oy + 1.0;
        $use->posZ = $oz + 0.5;
        $use->slot = 0;
        $use->item = [259, 1, 0, null];
        $pc->sendGamePacket($use);
        $kernel->run(2);
        ok($store->getBlock($ox + 1, $oy + 1, $oz) === 90, 'portal lit');

        // Park Pete inside the portal cell (server-side; the charge builds
        // over 80 survival ticks, then the dimension change fires).
        $entity = $kernel->getWorld()->getEntity($peteId);
        $pos = $entity?->get(\pocketmine\core\component\PositionComponent::class);
        ok($pos !== null, 'Pete position present');
        if ($pos === null) {
            return;
        }
        $pos->x = $ox + 1.5;
        $pos->y = $oy + 1.5;
        $pos->z = $oz + 0.5;

        // Creative crosses instantly (the survival 80-tick charge would need
        // ~20s of pumped ticks here, since each run(1) does real worldgen).
        $entity->get(\pocketmine\core\component\MetadataComponent::class)?->set('gamemode', 1);

        // Pump ticks until the dimension change lands (charge + world create
        // + nether spawn-chunk generation + stream).
        $deadline = microtime(true) + 15.0;
        $sawDim1 = false;
        while (microtime(true) < $deadline && !$sawDim1) {
            foreach ($pc->readGamePackets() as [$id, $buffer]) {
                if ($id === Info::CHANGE_DIMENSION_PACKET) {
                    $dim = new BinaryStream($buffer, 1);
                    if ($dim->getByte() === 1) {
                        $sawDim1 = true;
                    }
                }
            }
            $kernel->run(1);
        }
        ok($sawDim1, 'ChangeDimensionPacket (nether=1) sent when crossing');

        $netherId = $kernel->getWorldRegistry()->getWorldIdByName('nether');
        ok($netherId !== null, 'nether world auto-created on first portal use');
        $wc = $entity->get(\pocketmine\core\component\WorldComponent::class);
        ok($wc instanceof \pocketmine\core\component\WorldComponent && $wc->id === $netherId, 'Pete moved to the nether world');

        // Pete should be standing on netherrack at the nether safe spawn.
        $netherStore = $netherId !== null ? $kernel->getWorldRegistry()->getStore($netherId) : null;
        if ($netherStore !== null) {
            $under = $netherStore->getBlock((int)floor($pos->x), (int)floor($pos->y) - 1, (int)floor($pos->z));
            ok($under === 87 || $under === 89 || $under === 88, 'nether spawn stands on a nether block');
        } else {
            ok(false, 'nether store present');
        }

        // --- Return trip: light a portal in the nether where Pete stands. ---
        if ($netherStore !== null) {
            $netherStore->setBlock((int)floor($pos->x), (int)floor($pos->y), (int)floor($pos->z), 90, 0);
            $deadline = microtime(true) + 15.0;
            $sawDim0 = false;
            while (microtime(true) < $deadline && !$sawDim0) {
                foreach ($pc->readGamePackets() as [$id, $buffer]) {
                    if ($id === Info::CHANGE_DIMENSION_PACKET) {
                        $dim = new BinaryStream($buffer, 1);
                        if ($dim->getByte() === 0) {
                            $sawDim0 = true;
                        }
                    }
                }
                $kernel->run(1);
            }
            ok($sawDim0, 'ChangeDimensionPacket (normal=0) sent on the return trip');
            $wc = $entity->get(\pocketmine\core\component\WorldComponent::class);
            ok($wc instanceof \pocketmine\core\component\WorldComponent && $wc->id === 0, 'Pete returned to the overworld');
        }

        // Clean up: drop the nether world + the overworld frame so later
        // tests (and the autosave) see the original single-world state.
        if ($netherId !== null) {
            $kernel->getWorldRegistry()->removeWorld($netherId);
        }
        for ($i = 0; $i < 4; $i++) {
            for ($y = $oy; $y <= $oy + 4; $y++) {
                $store->setBlock($ox + $i, $y, $oz, 0, 0);
            }
        }
    } finally {
        $pc->close();
    }
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

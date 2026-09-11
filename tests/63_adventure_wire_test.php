<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/fake_client.php'; // FakeClient

use pocketmine\protocol\Info;
use pocketmine\protocol\PlayStatusPacket;
use pocketmine\protocol\SetPlayerGameTypePacket;
use pocketmine\protocol\TextPacket;
use pocketmine\utils\BinaryStream;

// Wire parsers: protocol-84 client-bound packets have no-op decode(), so the
// raw bodies are parsed directly (same approach as tests/17).

function sgGamemode(string $buf): int {
    $s = new BinaryStream($buf, 1);
    $s->getInt(); // seed
    $s->getByte(); // dimension
    $s->getInt(); // generator
    return $s->getInt(); // gamemode
}

function advFlags(string $buf): int {
    $s = new BinaryStream($buf, 1);
    return $s->getInt();
}

function spgtGamemode(string $buf): int {
    $s = new BinaryStream($buf, 1);
    return $s->getInt();
}

$port = 23000 + random_int(0, 20000);

$kernel = \pocketmine\bootstrap();
$kernel->setNetworkingEnabled(true);
$kernel->setBindPort($port);
$kernel->setAutoShutdownOnRun(false);

// Deterministic world (fixed seed, no mobs) like the other network tests.
$cfg = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ServerConfig::class);
if ($cfg instanceof \pocketmine\core\resource\ServerConfig) {
    $cfg->seed = 1;
}
// The whole point of this harness: an adventure join must send binary
// gamemode + adventure flags on the wire (old-src & 0x01 parity).
// PlayerJoinService reads WorldConfig::$gameMode for new players.
$worldCfg = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\WorldConfig::class);
if ($worldCfg instanceof \pocketmine\core\resource\WorldConfig) {
    $worldCfg->spawnMobs = false;
    $worldCfg->gameMode = \pocketmine\core\enum\GameMode::Adventure;
}
// Command rate limits would drop the back-to-back /gamemode in test 2.
$khronosCfg = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\KhronosConfig::class);
if ($khronosCfg instanceof \pocketmine\core\resource\KhronosConfig) {
    $khronosCfg->chatMinIntervalSeconds = 0.0;
    $khronosCfg->commandMinIntervalSeconds = 0.0;
}
// /gamemode is op-gated; grant alice before she joins.
$lists = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\PlayerListManager::class);
if ($lists instanceof \pocketmine\core\resource\PlayerListManager) {
    $lists->addOp('alice');
}

$kernel->run(1); // bind socket + start the RakNet thread + first tick

$client = new FakeClient($port);

// 0.15.10 AdventureSettings flag semantics (old-src sendSettings()).
const ADV_EXPECTED = 0x4E | 0x01;   // FLAGS_SURVIVAL base + adventure bit
const ADV_FORBIDDEN = 0x80 | 0x100; // allow_fly, noclip

$bursts = []; // list<array{id: int, buffer: string}> - every packet since login

test('adventure join sends binary gamemode (0) + flags with the adventure bit', function () use ($client, $kernel, &$bursts): void {
    $client->handshake(fn() => $kernel->run(1));
    $client->connect(fn() => $kernel->run(1));

    $client->sendLogin('Alice', '11111111-2222-3333-4444-555555555555');
    $kernel->run(2);

    // Collect the login burst until PLAYER_SPAWN and at least one
    // AdventureSettings after it (doFirstSpawn fires there).
    $deadline = microtime(true) + 8.0;
    $sawSpawn = false;
    $sawAdvAfterSpawn = false;
    while (microtime(true) < $deadline && !($sawSpawn && $sawAdvAfterSpawn)) {
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            $bursts[] = [$id, $buffer];
            if ($id === Info::PLAY_STATUS_PACKET && psStatusRaw($buffer) === PlayStatusPacket::PLAYER_SPAWN) {
                $sawSpawn = true;
            }
            if ($id === Info::ADVENTURE_SETTINGS_PACKET && $sawSpawn) {
                $sawAdvAfterSpawn = true;
            }
        }
        $kernel->run(1);
    }
    ok($sawSpawn, 'login reached PLAYER_SPAWN');
    ok($sawAdvAfterSpawn, 'AdventureSettings arrived after PLAYER_SPAWN (doFirstSpawn burst)');

    $startGameGamemode = null;
    $joinAdvFlags = [];
    foreach ($bursts as [$id, $buffer]) {
        if ($id === Info::START_GAME_PACKET) {
            $startGameGamemode = sgGamemode($buffer);
        }
        if ($id === Info::ADVENTURE_SETTINGS_PACKET) {
            $joinAdvFlags[] = advFlags($buffer);
        }
    }
    ok($startGameGamemode !== null, 'StartGamePacket received');
    ok($startGameGamemode === 0, "StartGame gamemode is binary 0 for adventure (got $startGameGamemode)");
    ok($joinAdvFlags !== [], 'AdventureSettings received on join');
    foreach ($joinAdvFlags as $flags) {
        ok(($flags & ADV_EXPECTED) === ADV_EXPECTED, "join flags contain survival base + adventure bit (got 0x" . dechex($flags) . ")");
        ok(($flags & ADV_FORBIDDEN) === 0, "join flags have no allow_fly/noclip bits (got 0x" . dechex($flags) . ")");
    }
});

test('/gamemode 1 sends binary SetPlayerGameType (1) with creative flags', function () use ($client, $kernel): void {
    $cmd = new TextPacket();
    $cmd->type = TextPacket::TYPE_CHAT;
    $cmd->source = 'Alice';
    $cmd->message = '/gamemode 1';
    $client->sendGamePacket($cmd);
    $kernel->run(3);

    $deadline = microtime(true) + 5.0;
    $spgt = null;
    $advAfterCmd = [];
    while (microtime(true) < $deadline && ($spgt === null || $advAfterCmd === [])) {
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::SET_PLAYER_GAMETYPE_PACKET) {
                $spgt = spgtGamemode($buffer);
            }
            if ($id === Info::ADVENTURE_SETTINGS_PACKET) {
                $advAfterCmd[] = advFlags($buffer);
            }
        }
        $kernel->run(1);
    }
    ok($spgt !== null, 'SetPlayerGameTypePacket received after /gamemode 1');
    ok($spgt === 1, "SetPlayerGameType wire gamemode is binary 1 for creative (got " . var_export($spgt, true) . ")");
    ok($advAfterCmd !== [], 'AdventureSettings received after /gamemode 1');
    // 0.15 creative flag set (0xCE) has no noclip bit - only spectator gets 0x100.
    foreach ($advAfterCmd as $flags) {
        ok(($flags & 0x80) !== 0, "creative flags allow flight (got 0x" . dechex($flags) . ")");
        ok(($flags & 0x100) === 0, "creative flags have no noclip bit (got 0x" . dechex($flags) . ")");
    }
});

test('/gamemode 2 (adventure) sends binary 0 + adventure bit, no fly/noclip', function () use ($client, $kernel): void {
    $cmd = new TextPacket();
    $cmd->type = TextPacket::TYPE_CHAT;
    $cmd->source = 'Alice';
    $cmd->message = '/gamemode 2';
    $client->sendGamePacket($cmd);
    $kernel->run(3);

    $deadline = microtime(true) + 5.0;
    $spgt = null;
    $advAfterCmd = [];
    while (microtime(true) < $deadline && ($spgt === null || $advAfterCmd === [])) {
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === Info::SET_PLAYER_GAMETYPE_PACKET) {
                $spgt = spgtGamemode($buffer);
            }
            if ($id === Info::ADVENTURE_SETTINGS_PACKET) {
                $advAfterCmd[] = advFlags($buffer);
            }
        }
        $kernel->run(1);
    }
    ok($spgt !== null, 'SetPlayerGameTypePacket received after /gamemode 2');
    ok($spgt === 0, "SetPlayerGameType wire gamemode is binary 0 for adventure (got " . var_export($spgt, true) . ")");
    ok($advAfterCmd !== [], 'AdventureSettings received after /gamemode 2');
    foreach ($advAfterCmd as $flags) {
        ok(($flags & ADV_EXPECTED) === ADV_EXPECTED, "adventure flags contain survival base + adventure bit (got 0x" . dechex($flags) . ")");
        ok(($flags & ADV_FORBIDDEN) === 0, "adventure flags have no allow_fly/noclip bits (got 0x" . dechex($flags) . ")");
    }
});

/** PlayStatus body = int status (mirror of tests/17's psStatus, local to this file). */
function psStatusRaw(string $buf): int {
    $s = new BinaryStream($buf, 1);
    return $s->getInt();
}

test('shutdown completes cleanly', function () use ($client, $kernel): void {
    $client->close();
    $kernel->shutdown();
    ok(true, 'kernel thread stopped');
});

exit(runTests());

#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Network-wire benchmark (14.34).
 *
 * Boots the real stack (RakNet thread + adapter + NetworkSessionService),
 * connects a fake client, logs in, then measures what players actually feel:
 *   1. chunk streaming rate (chunks/sec to the client over the wire),
 *   2. per-tick network-flush cost with a connected client (the flush is
 *      inside the 20 TPS budget),
 *   3. inbound packet processing rate (move packets echoed to the client),
 *   4. latency: login -> StartGame packet round trip.
 *
 * Usage: bin/php7/bin/php measure_network.php [viewRadius]
 *   viewRadius  chunk radius to stream (default: 8)
 */

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../tests/fake_client.php';

use pocketmine\protocol\BatchPacket;
use pocketmine\protocol\ContainerSetContentPacket;
use pocketmine\protocol\FullChunkDataPacket;
use pocketmine\protocol\Info;
use pocketmine\protocol\LoginPacket;
use pocketmine\protocol\MobEquipmentPacket;
use pocketmine\protocol\MovePlayerPacket;
use pocketmine\protocol\PlayerActionPacket;
use pocketmine\protocol\RequestChunkRadiusPacket;
use pocketmine\protocol\StartGamePacket;
use pocketmine\protocol\TextPacket;
use pocketmine\protocol\UpdateBlockPacket;
use pocketmine\utils\BinaryStream;

function psStatus(string $buf): int {
    $s = new BinaryStream($buf, 1);
    return $s->getInt();
}

$view = (int)($argv[1] ?? 8);

// Back-to-back ticks (no 50ms 20-TPS pacing sleep) so the numbers are CPU
// cost, not clock waits.
putenv('KHRONOS_FAST_TICKS=1');

$worldsDir = getcwd() . '/worlds';
if (is_dir($worldsDir)) {
    exec('rm -rf ' . escapeshellarg($worldsDir));
}

$port = 30000 + random_int(0, 10000);
$kernel = \pocketmine\bootstrap();
$kernel->setNetworkingEnabled(true);
$kernel->setBindPort($port);
$kernel->setAutoShutdownOnRun(false);

$serverCfg = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ServerConfig::class);
if ($serverCfg instanceof \pocketmine\core\resource\ServerConfig) {
    $serverCfg->seed = 1;
    $serverCfg->spawnMobs = false;
}

$kernel->run(1);
$client = new FakeClient($port);

// --- RakNet handshake + login ---------------------------------------------
$t0 = microtime(true);
$client->handshake(fn() => $kernel->run(1));
$client->connect(fn() => $kernel->run(1));
$tHandshake = (microtime(true) - $t0) * 1000;

$t0 = microtime(true);
$client->sendLogin('bench_player', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
$startGame = null;
$chunks = 0;
$chunkBytes = 0;
$deadline = microtime(true) + 20.0;
$sentChunkRequest = false;
while (microtime(true) < $deadline) {
    $kernel->run(1);
    usleep(2000); // let the network thread deliver before draining
    foreach ($client->readGamePackets() as [$pid, $buf]) {
        if ($pid === Info::PLAY_STATUS_PACKET && psStatus($buf) === 3 && $startGame === null) {
            $startGame = $buf; // PLAYER_SPAWN marks StartGame processed
        }
        if ($pid === Info::FULL_CHUNK_DATA_PACKET) {
            $chunks++;
            $chunkBytes += strlen($buf);
        }
    }
    if (!$sentChunkRequest && $startGame !== null) {
        // StartGame done: ask for the full view radius now.
        $rcr = new RequestChunkRadiusPacket();
        $rcr->radius = $view;
        $client->sendGamePacket($rcr);
        $sentChunkRequest = true;
    }
    // Stop when the expected ring is in.
    $expected = (2 * $view + 1) ** 2;
    if ($chunks >= $expected && $startGame !== null) {
        break;
    }
}
$tLoginToSpawn = (microtime(true) - $t0) * 1000;

// --- Network flush cost per tick (steady state, connected client) ----------
// Measure the whole kernel tick loop with the client connected; the tick
// includes flushNetworkSync. Run back-to-back (hot) to avoid the frequency
// scaling artifact the other benchmarks document.
$hotTicks = 100;
$warm = 10;
for ($i = 0; $i < $warm; $i++) {
    $kernel->run(1);
}
$t0 = hrtime(true);
for ($i = 0; $i < $hotTicks; $i++) {
    $kernel->run(1);
}
$tickWithNetMs = (hrtime(true) - $t0) / 1e6 / $hotTicks;

// --- Inbound throughput: echo movement packets back ------------------------
$moves = 2000;
$t0 = hrtime(true);
for ($i = 0; $i < $moves; $i++) {
    $mp = new MovePlayerPacket();
    $mp->eid = 1;
    $mp->x = (float)($i % 50);
    $mp->y = 70.0 + ($i % 5);
    $mp->z = (float)intdiv($i, 50);
    $mp->yaw = (float)($i % 360);
    $mp->pitch = 0.0;
    $mp->mode = 0; // NORMAL
    $client->sendGamePacket($mp);
    if ($i % 20 === 0) {
        $kernel->run(1);
        $client->readGamePackets();
    }
}
$kernel->run(1);
$client->readGamePackets();
$moveMs = (hrtime(true) - $t0) / 1e6;

// --- Hot pure-sim tick (no client work, for comparison) --------------------
// Drain client buffered reads, then measure the ECS loop alone.
$client->close();
$kernel->setNetworkingEnabled(false);
$hotTicks = 100;
$t0 = hrtime(true);
for ($i = 0; $i < $hotTicks; $i++) {
    $kernel->run(1);
}
$tickNoNetMs = (hrtime(true) - $t0) / 1e6 / $hotTicks;

$expected = (2 * $view + 1) ** 2;
printf(
    "handshake=%.1fms login->spawn=%.1fms chunks=%d/%d (%.1f%% of view radius %d) chunkBytes=%.1fMB streamRate=%.0f chunks/sec\n",
    $tHandshake,
    $tLoginToSpawn,
    $chunks,
    $expected,
    $expected > 0 ? $chunks / $expected * 100 : 0,
    $view,
    $chunkBytes / 1048576,
    $tLoginToSpawn > 0 ? $chunks / ($tLoginToSpawn / 1000) : 0
);
printf("tick_with_net=%.3fms tick_no_net=%.3fms net_overhead=%.3fms\n", $tickWithNetMs, $tickNoNetMs, max(0, $tickWithNetMs - $tickNoNetMs));
printf("inbound_move_processing: %d moves in %.1fms = %.0f packets/sec (%.2fus each)\n", $moves, $moveMs, $moveMs > 0 ? $moves / ($moveMs / 1000) : 0, $moveMs > 0 ? $moveMs * 1000 / $moves : 0);

$kernel->shutdown();

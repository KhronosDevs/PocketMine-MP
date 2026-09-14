<?php

declare(strict_types=1);

/**
 * Shared helpers for the security/ hostile-input scripts.
 *
 * Conventions:
 *  - Scripts are self-contained: they boot their own throwaway server state
 *    in a per-process temp dir (never the repo's worlds/) and clean up after
 *  - Everything talks to 127.0.0.1 on a random high port
 *  - A script exits 0 only when every scenario passed
 *  - No personal data, names or absolute personal paths anywhere
 */

require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/tests/helpers.php';
require dirname(__DIR__) . '/tests/fake_client.php';

use pocketmine\Kernel;

/** Boot a fresh kernel with networking, in an isolated temp data dir.
 *  Returns [Kernel, boundPort]. */
function sec_boot(string $label, ?callable $tweak = null): array {
    $work = sys_get_temp_dir() . '/khronos_sec_' . $label . '_' . getmypid();
    if (is_dir($work)) {
        exec('rm -rf ' . escapeshellarg($work));
    }
    mkdir($work . '/worlds', 0755, true);
    chdir($work);

    $port = 22000 + random_int(0, 20000);
    $kernel = \pocketmine\bootstrap();
    $kernel->setNetworkingEnabled(true);
    $kernel->setBindPort($port);
    $kernel->setAutoShutdownOnRun(false);

    $sc = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ServerConfig::class);
    if ($sc instanceof \pocketmine\core\resource\ServerConfig) {
        $sc->seed = 1; // deterministic terrain, as in tests/17
    }
    $wc = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\WorldConfig::class);
    if ($wc instanceof \pocketmine\core\resource\WorldConfig) {
        $wc->spawnMobs = false;
    }
    if ($tweak !== null) {
        $tweak($kernel);
    }
    $kernel->run(1); // bind socket + start the RakNet thread + first tick
    return [$kernel, $port];
}

/** Drain RakLib thread logs; FAIL on any critical line (wire-thread crash). */
function sec_check_wire_errors(Kernel $kernel, string $scenario): void {
    $adapter = $kernel->getNetworkPort();
    if ($adapter instanceof \pocketmine\adapter\driven\network\Protocol84NetworkAdapter) {
        foreach ($adapter->drainLogLines() as $line) {
            if (str_starts_with($line, 'critical')) {
                sec_fail($scenario, 'RakLib thread logged a critical error: ' . $line);
                return;
            }
        }
    }
    sec_pass($scenario);
}

/** Lift a wire-layer block for a loopback address after a flood scenario. */
function sec_unblock_loopback(Kernel $kernel): void {
    $adapter = $kernel->getNetworkPort();
    if ($adapter instanceof \pocketmine\adapter\driven\network\Protocol84NetworkAdapter) {
        $adapter->unblockAddress('127.0.0.1');
    }
}

/** Expect the client to receive a DisconnectPacket within the deadline. */
function sec_await_disconnect(FakeClient $client, Kernel $kernel, float $timeoutSec = 4.0): bool {
    $deadline = microtime(true) + $timeoutSec;
    while (microtime(true) < $deadline) {
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            if ($id === \pocketmine\protocol\Info::DISCONNECT_PACKET) {
                return true;
            }
        }
        $kernel->run(1);
        usleep(10000);
    }
    return false;
}

/** Wait until the client has fully spawned (PLAYER_SPAWN play status).
 *  Records every packet body seen into $GLOBALS['sec_seen'][id] (first wins)
 *  so callers can inspect the login burst afterwards. */
function sec_await_spawn(FakeClient $client, Kernel $kernel, float $timeoutSec = 8.0): bool {
    $sawSpawn = false;
    $GLOBALS['sec_seen'] = [];
    $deadline = microtime(true) + $timeoutSec;
    while (microtime(true) < $deadline && !$sawSpawn) {
        foreach ($client->readGamePackets() as [$id, $buffer]) {
            $GLOBALS['sec_seen'][$id] ??= $buffer;
            if ($id === \pocketmine\protocol\Info::PLAY_STATUS_PACKET) {
                $s = new \pocketmine\utils\BinaryStream($buffer, 1);
                if ($s->getInt() === \pocketmine\protocol\PlayStatusPacket::PLAYER_SPAWN) {
                    $sawSpawn = true;
                }
            }
        }
        $kernel->run(1);
        usleep(5000);
    }
    return $sawSpawn;
}

/** Send a login packet with an arbitrary (hostile) skin payload and name. */
function sec_send_hostile_login(FakeClient $client, string $username, string $skinData): void {
    $jwt = static function (array $payload): string {
        $b64 = static fn(string $s): string => base64_encode($s);
        return $b64('{"alg":"ES384"}') . '.' . $b64(json_encode($payload)) . '.' . $b64(str_repeat("\x00", 97));
    };
    $uuid = '77777777-8888-9999-aaaa-bbbbccccdddd';
    $chain = json_encode(['chain' => [$jwt([
        'extraData' => ['displayName' => $username, 'identity' => $uuid],
    ])]]);
    $skin = $jwt([
        'ClientRandomId' => 98765,
        'ServerAddress' => '127.0.0.1:0',
        'SkinData' => base64_encode($skinData),
        'SkinId' => 'Standard_Custom',
    ]);

    $inner = new \pocketmine\utils\BinaryStream();
    $inner->putLInt(strlen((string)$chain));
    $inner->put((string)$chain);
    $inner->putLInt(strlen((string)$skin));
    $inner->put((string)$skin);

    $compressed = zlib_encode($inner->getBuffer(), ZLIB_ENCODING_DEFLATE, 7);
    $stream = new \pocketmine\utils\BinaryStream();
    $stream->putInt(84);
    $stream->putInt(strlen($compressed));
    $stream->put($compressed);

    $login = new \pocketmine\protocol\LoginPacket();
    $login->setBuffer(chr(\pocketmine\protocol\Info::LOGIN_PACKET) . $stream->getBuffer());
    $client->sendGamePacket($login);
}

// --- assertion helpers (process-wide failure counter) ----------------------

$GLOBALS['sec_failures'] = 0;

function sec_pass(string $name): void {
    echo 'PASS ' . $name . PHP_EOL;
}

function sec_fail(string $name, string $why): void {
    $GLOBALS['sec_failures']++;
    echo 'FAIL ' . $name . ': ' . $why . PHP_EOL;
}

function sec_ok(bool $cond, string $name, string $why = 'condition was false'): void {
    if ($cond) {
        sec_pass($name);
    } else {
        sec_fail($name, $why);
    }
}

/** Print the summary and return the process exit code. */
function sec_summary(string $file): int {
    $n = $GLOBALS['sec_failures'];
    if ($n === 0) {
        echo '[security] ' . $file . ': ALL SCENARIOS PASSED' . PHP_EOL;
        return 0;
    }
    echo '[security] ' . $file . ': ' . $n . ' SCENARIO(S) FAILED' . PHP_EOL;
    return 1;
}

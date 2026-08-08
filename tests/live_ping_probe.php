<?php

declare(strict_types=1);

/**
 * Live UDP ping probe: boots the real server in the background and sends a
 * genuine RakNet UNCONNECTED_PING (0x01) to verify the server answers with a
 * PONG carrying the MCPE motd a real 0.15.10 client parses.
 *
 * Usage: bin/php7/bin/php tests/live_ping_probe.php
 */

use raklib\Binary;
use raklib\RakLib;

require __DIR__ . '/../autoload.php';

$port = (int)(getenv('KHRONOS_PROBE_PORT') ?: 19199); // test port

// Spawn the server with proc_open and fully detached descriptors (the pmmpthread
// server keeps a shell_exec pipe open forever, so shell_exec/nohup cannot be
// used). The child's stdout/stderr go to the log file; stdin is /dev/null.
$root = dirname(__DIR__);
$logFile = '/tmp/live_ping_server.log';
$descriptors = [
    0 => ['file', '/dev/null', 'r'],
    1 => ['file', $logFile, 'w'],
    2 => ['file', $logFile, 'a'],
];
$env = ['KHRONOS_PROBE_PORT' => (string)$port] + getenv();
$cmd = [
    PHP_BINARY,
    __DIR__ . '/live_ping_boot.php',
];
$proc = proc_open($cmd, $descriptors, $pipes, $root, $env);
if (!is_resource($proc)) {
    fwrite(STDERR, "proc_open failed\n");
    exit(2);
}
$status = proc_get_status($proc);
echo "server pid: " . ($status['pid'] ?? '?') . "\n";

// Fixed boot wait (the RakLib thread autoloads then binds; ~2-3s on this box).
sleep(6);

// Build UNCONNECTED_PING (0x01): pingID(long) + magic(16) + clientGUID(long)
$ping = chr(0x01) . Binary::writeLong(123456789) . RakLib::MAGIC . Binary::writeLong(987654321);

// A socket that sent to a not-yet-listening port collects an ICMP error and
// every later recvfrom on it fails with ECONNREFUSED - so use a FRESH socket
// per attempt with a 1s receive timeout.
$buf = '';
$from = '';
$fromPort = 0;
$recv = false;
for ($attempt = 0; $attempt < 10; $attempt++) {
    $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if ($sock === false) {
        break;
    }
    socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);
    $ok = @socket_sendto($sock, $ping, strlen($ping), 0, '127.0.0.1', $port);
    if ($ok !== false) {
        $recv = @socket_recvfrom($sock, $buf, 2048, 0, $from, $fromPort);
        if ($recv !== false && $recv >= 1) {
            socket_close($sock);
            break;
        }
    }
    socket_close($sock);
    usleep(150000);
}

// Diagnostics before giving up.
if ($recv === false || $recv < 1) {
    echo "--- diagnostics ---\n";
    $log = @file_get_contents($logFile);
    foreach (preg_split('/\R/', (string)$log) as $line) {
        if ($line !== '' && !str_contains($line, 'already loaded')) {
            echo "log: $line\n";
        }
    }
    $st = proc_get_status($proc);
    echo "server alive: " . ($st['running'] ? 'yes' : 'no') . "\n";
    echo "--- end diagnostics ---\n";
    proc_terminate($proc);
    proc_close($proc);
    exit(4);
}
$id = ord($buf[0]);
echo "received datagram: id=0x" . dechex($id) . " len=$recv from $from:$fromPort\n";
if ($id !== 0x1c) { // UNCONNECTED_PONG
    echo "FAIL: expected UNCONNECTED_PONG (0x1c), got 0x" . dechex($id) . "\n";
    proc_terminate($proc);
    proc_close($proc);
    exit(5);
}
// decode: pingID(8) serverID(8) magic(16) then putString name (short len + bytes)
$pingID = Binary::readLong(substr($buf, 1, 8));
$serverID = Binary::readLong(substr($buf, 9, 8));
$magic = substr($buf, 17, 16);
$nameLen = unpack('n', substr($buf, 33, 2))[1];
$name = substr($buf, 35, $nameLen);
echo "pingID: $pingID (expected 123456789)\n";
echo "serverID: $serverID\n";
echo "magic ok: " . ($magic === RakLib::MAGIC ? 'yes' : 'NO') . "\n";
echo "motd: $name\n";

$okMotd = str_starts_with($name, 'MCPE;');
$fields = explode(';', $name);
echo "fields: " . count($fields) . " (name='" . ($fields[1] ?? '?') . "' protocol='" . ($fields[2] ?? '?') . "' online='" . ($fields[4] ?? '?') . "' max='" . ($fields[5] ?? '?') . "')\n";
$protocolOk = ($fields[2] ?? '') === '84';
$countsOk = count($fields) >= 6;

proc_terminate($proc);
proc_close($proc);

if (!$okMotd || !$protocolOk || !$countsOk) {
    echo "FAIL: motd not client-parseable\n";
    exit(6);
}
echo "PASS: server answers UNCONNECTED_PING with a client-parseable MCPE motd\n";
exit(0);

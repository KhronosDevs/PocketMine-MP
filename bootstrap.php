<?php

declare(strict_types=1);

require_once __DIR__ . '/autoload.php';

use pocketmine\utils\Terminal;
use function pocketmine\bootstrap;

Terminal::init();
$kernel = bootstrap();

// Real server: serve clients. run() binds the UDP socket and the session
// service processes the login/chunk/movement flows.
$kernel->setNetworkingEnabled(true);

$port = $kernel->getNetworkPort() instanceof \pocketmine\adapter\driven\network\Protocol84NetworkAdapter
    ? $kernel->getNetworkPort()->getBindPort()
    : 'unknown';
echo '[Khronos] Listening on UDP ' . $port . PHP_EOL;
echo '[Khronos] Server started - waiting for 0.15.10 clients (protocol 84)' . PHP_EOL;

$kernel->run();
<?php

declare(strict_types=1);

require_once __DIR__ . '/autoload.php';

use function pocketmine\bootstrap;

$kernel = bootstrap();

// Real server: serve clients. run() binds the UDP socket and the session
// service processes the login/chunk/movement flows.
$kernel->setNetworkingEnabled(true);

$kernel->run();
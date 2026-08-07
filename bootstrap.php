<?php

declare(strict_types=1);

require_once __DIR__ . '/autoload.php';

use function pocketmine\bootstrap;

$kernel = bootstrap();

// Start network adapter
$networkPort = $kernel->getNetworkPort();
if ($networkPort instanceof \pocketmine\adapter\driven\network\Protocol84NetworkAdapter) {
    $networkPort->start();
}

$kernel->run();
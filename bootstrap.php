<?php

declare(strict_types=1);

require_once __DIR__ . '/spl/ClassLoader.php';
require_once __DIR__ . '/spl/BaseClassLoader.php';
require_once __DIR__ . '/pocketmine/CompatibleClassLoader.php';

use pocketmine\Kernel;

$loader = new \pocketmine\CompatibleClassLoader();
$loader->addPsr4('pocketmine\\', __DIR__ . '/pocketmine');
$loader->register(true);

$kernel = bootstrap();

// Start network adapter
$networkPort = $kernel->getNetworkPort();
if ($networkPort instanceof \pocketmine\adapter\driven\network\Protocol84NetworkAdapter) {
    $networkPort->start();
}

$kernel->run();
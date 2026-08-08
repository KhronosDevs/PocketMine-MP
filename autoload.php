<?php

declare(strict_types=1);

/**
 * Autoloader for the new pocketmine structure
 */

$loader = require __DIR__ . '/vendor/autoload.php';

// Register PSR-4 namespaces for the new structure
$loader->addPsr4('pocketmine\\', __DIR__ . '/src/pocketmine');
$loader->addPsr4('raklib\\', __DIR__ . '/src/raklib');

// Load bootstrap functions
require_once __DIR__ . '/src/pocketmine/Kernel.php';

return $loader;
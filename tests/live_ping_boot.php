<?php

declare(strict_types=1);

/**
 * Boot the server for the live UDP ping probe. Mirrors bootstrap.php but binds
 * the probe port (KHRONOS_PROBE_PORT, default 19199) so it never clashes with
 * a real server on the default 19132.
 */

require __DIR__ . '/../autoload.php';

use function pocketmine\bootstrap;

$kernel = bootstrap();
$kernel->setNetworkingEnabled(true);
$kernel->setBindPort((int)(getenv('KHRONOS_PROBE_PORT') ?: 19199));
$kernel->run();

<?php

declare(strict_types=1);

/**
 * Loads one or more plugin folders from the repo's plugins/ directory against
 * the new Khronos API and reports which ones enabled successfully.
 *
 * Usage (from repo root, with the bundled php):
 *   bin/php7/bin/php scripts/check-plugin.php PartyCore
 *   bin/php7/bin/php scripts/check-plugin.php PartyCore CorePvP Golf
 *   bin/php7/bin/php scripts/check-plugin.php all
 *
 * Each plugin is loaded into a fresh kernel (static ECS state is
 * process-scoped, so only one kernel per invocation). A plugin that fails to
 * parse/load/enable is reported with the error captured from the manager.
 */

require __DIR__ . '/../autoload.php';

use pocketmine\Kernel;

$repoPlugins = __DIR__ . '/../plugins';
$requested = array_slice($argv ?? [], 1);
if (empty($requested)) {
    fwrite(STDERR, "Usage: php scripts/check-plugin.php <PluginName>... | all\n");
    exit(2);
}
if (in_array('all', $requested, true)) {
    $requested = array_values(array_filter(scandir($repoPlugins) ?: [], fn(string $e): bool =>
        is_dir("$repoPlugins/$e") && is_file("$repoPlugins/$e/plugin.yml")
    ));
}

// Capture error_log() output so per-plugin enable failures are visible.
$errorLog = '';
set_error_handler(function (int $severity, string $message, string $file, int $line) use (&$errorLog): bool {
    $errorLog .= "$message in $file on line $line\n";
    return true;
});

$failed = false;
foreach ($requested as $name) {
    $errorLog = '';
    $path = "$repoPlugins/$name";
    if (!is_dir($path) || !is_file("$path/plugin.yml")) {
        echo "[SKIP] $name: not a plugin directory\n";
        continue;
    }
    $kernel = \pocketmine\bootstrap();
    $manager = $kernel->getPluginManager();
    $plugin = $manager->loadPlugin($path);
    if ($plugin !== null && $plugin->isEnabled()) {
        echo "[ OK ] $name enabled\n";
    } else {
        $failed = true;
        echo "[FAIL] $name did not enable\n";
        if ($errorLog !== '') {
            foreach (explode("\n", trim($errorLog)) as $line) {
                echo "       $line\n";
            }
        }
    }
    $kernel->shutdown();
}

exit($failed ? 1 : 0);
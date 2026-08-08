<?php

declare(strict_types=1);

namespace pocketmine\port\driving;

use pocketmine\api\plugin\Plugin;

/**
 * The plugin seam. Implemented by the api PluginManager so plugins, the port
 * layer and the Server facade all share the same registry.
 */
interface PluginPort {
    public function loadPlugin(string $path): ?Plugin;

    public function enablePlugin(Plugin $plugin): void;

    public function disablePlugin(Plugin $plugin): void;

    public function getPlugin(string $name): ?Plugin;

    public function getPlugins(): array;
}

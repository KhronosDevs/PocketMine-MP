<?php

declare(strict_types=1);

namespace pocketmine\adapter\driving\plugin;

use pocketmine\port\driving\CommandPort;
use pocketmine\port\driving\EventPort;
use pocketmine\port\driving\Plugin;
use pocketmine\port\driving\PluginDescription;
use pocketmine\port\driving\PluginPort;

final class PluginManagerAdapter implements PluginPort {
    private array $plugins = [];

    public function __construct(
        private readonly CommandPort $commandPort,
        private readonly EventPort $eventPort,
    ) {}

    public function loadPlugin(string $path): ?Plugin {
        // TODO: Implement plugin loading from .phar or directory
        return null;
    }

    public function enablePlugin(Plugin $plugin): void {
        $this->plugins[$plugin->getName()] = $plugin;
        $plugin->onEnable();
    }

    public function disablePlugin(Plugin $plugin): void {
        if (isset($this->plugins[$plugin->getName()])) {
            $plugin->onDisable();
            unset($this->plugins[$plugin->getName()]);
        }
    }

    public function getPlugin(string $name): ?Plugin {
        return $this->plugins[$name] ?? null;
    }

    public function getPlugins(): array {
        return array_values($this->plugins);
    }
}
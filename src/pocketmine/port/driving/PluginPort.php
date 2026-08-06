<?php

declare(strict_types=1);

namespace pocketmine\port\driving;

interface PluginPort {
    public function loadPlugin(string $path): ?Plugin;

    public function enablePlugin(Plugin $plugin): void;

    public function disablePlugin(Plugin $plugin): void;

    public function getPlugin(string $name): ?Plugin;

    public function getPlugins(): array;
}
<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\plugin\Plugin;

/**
 * Fires when a plugin is disabled (after its onDisable() hook runs).
 */
class PluginDisableEvent extends Event {
    public function __construct(
        public readonly Plugin $plugin,
    ) {}

    public function getPlugin(): Plugin {
        return $this->plugin;
    }
}

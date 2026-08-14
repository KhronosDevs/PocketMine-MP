<?php

declare(strict_types=1);

namespace pocketmine\api\command\builtin;

use pocketmine\api\command\CommandSender;

/**
 * /plugins — list the currently loaded plugins (name + version).
 */
final class PluginsCommand extends BuiltinCommand {
    public function __construct() {
        parent::__construct(
            'plugins',
            'List loaded plugins',
            '/plugins',
            ['pl'],
            'khronos.command.plugins',
        );
    }

    public function execute(CommandSender $sender, array $args): bool {
        $kernel = $this->kernel();
        if ($kernel === null) {
            return false;
        }
        $pluginPort = $kernel->getPluginPort();
        $plugins = method_exists($pluginPort, 'getPlugins') ? $pluginPort->getPlugins() : [];
        if ($plugins === []) {
            $sender->sendMessage('No plugins loaded.');
            return true;
        }
        $names = [];
        foreach ($plugins as $plugin) {
            $names[] = $plugin->getName() . ' v' . $plugin->getVersion();
        }
        $sender->sendMessage('Plugins (' . count($names) . '): ' . implode(', ', $names));
        return true;
    }
}

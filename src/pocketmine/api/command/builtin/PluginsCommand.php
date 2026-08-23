<?php

declare(strict_types=1);

namespace pocketmine\api\command\builtin;

use pocketmine\api\command\CommandSender;
use pocketmine\api\command\Format;

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
            category: 'general',
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
            $sender->sendMessage(Format::MUTED . 'No plugins loaded.' . Format::RESET);
            return true;
        }
        $lines = [];
        foreach ($plugins as $plugin) {
            $lines[] = Format::GREEN . $plugin->getName() . Format::MUTED . ' v' . Format::VALUE . $plugin->getVersion() . Format::RESET;
        }
        $sender->sendMessage(Format::header('Plugins') . ' ' . Format::VALUE . count($lines) . Format::RESET);
        $sender->sendMessage(Format::MUTED . implode(', ', $lines) . Format::RESET);
        return true;
    }
}

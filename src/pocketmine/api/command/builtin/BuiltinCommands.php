<?php

declare(strict_types=1);

namespace pocketmine\api\command\builtin;

use pocketmine\port\driving\CommandPort;

/**
 * Registration point for the server's builtin command set. Called once at
 * kernel bootstrap so the console, plugins and players all share the same
 * registry (CommandMap implements CommandPort).
 */
final class BuiltinCommands {
    public static function registerAll(CommandPort $port): void {
        $port->register(new GamemodeCommand());
        $port->register(new TeleportCommand());
        $port->register(new GiveCommand());
        $port->register(new KillCommand());
        $port->register(new TimeCommand());
        $port->register(new WeatherCommand());
        $port->register(new WorldCommand());
        $port->register(new HelpCommand());
    }
}

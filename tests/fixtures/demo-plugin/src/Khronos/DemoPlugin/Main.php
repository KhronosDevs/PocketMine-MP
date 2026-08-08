<?php

declare(strict_types=1);

namespace Khronos\DemoPlugin;

use pocketmine\api\command\Command;
use pocketmine\api\command\CommandSender;
use pocketmine\api\plugin\Plugin;

class Main extends Plugin {
    public bool $loaded = false;
    public bool $enabled = false;

    public function onLoad(): void {
        $this->loaded = true;
    }

    public function onEnable(): void {
        $this->enabled = true;
        $this->registerCommand(new DemoEchoCommand());
    }

    public function onCommand(CommandSender $sender, string $label, array $args): bool {
        $sender->sendMessage('Demo says hi');
        return true;
    }
}

class DemoEchoCommand extends Command {
    public function __construct() {
        parent::__construct('demoecho', 'Echoes text back', '/demoecho <text>', ['de']);
    }

    public function execute(CommandSender $sender, array $args): bool {
        $sender->sendMessage('echo: ' . implode(' ', $args));
        return true;
    }
}

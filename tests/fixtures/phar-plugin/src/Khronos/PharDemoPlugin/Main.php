<?php

declare(strict_types=1);

namespace Khronos\PharDemoPlugin;

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
        $this->registerCommand(new PHelloCommand());
    }

    public function onCommand(CommandSender $sender, string $label, array $args): bool {
        $sender->sendMessage('PharDemo says hi');
        return true;
    }
}

class PHelloCommand extends Command {
    public function __construct() {
        parent::__construct('phello', 'Greets from the phar', '/phello', ['ph']);
    }

    public function execute(CommandSender $sender, array $args): bool {
        $sender->sendMessage('phello!');
        return true;
    }
}

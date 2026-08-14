<?php

declare(strict_types=1);

namespace pocketmine\api\command\builtin;

use pocketmine\api\command\CommandSender;

/**
 * /whitelist [on|off|add <player>|remove <player>|list] — manage the
 * whitelist. The on/off toggle is persisted via the Server facade's
 * white-list flag (server.properties white-list=on is the boot default);
 * the entry add/remove/list operate on white-list.txt.
 */
final class WhitelistCommand extends BuiltinCommand {
    public function __construct() {
        parent::__construct(
            'whitelist',
            'Manage the whitelist',
            '/whitelist <on|off|add|remove|list> [player]',
            ['wl'],
            'khronos.command.whitelist',
        );
    }

    public function execute(CommandSender $sender, array $args): bool {
        $action = strtolower(array_shift($args) ?? 'list');
        $lists = $this->playerLists();
        if ($lists === null) {
            return false;
        }
        switch ($action) {
            case 'on':
            case 'enable':
                $this->setWhitelistEnabled(true);
                $sender->sendMessage('Whitelist enabled.');
                return true;
            case 'off':
            case 'disable':
                $this->setWhitelistEnabled(false);
                $sender->sendMessage('Whitelist disabled.');
                return true;
            case 'add':
                $name = array_shift($args);
                if ($name === null || $name === '') {
                    $sender->sendMessage('Usage: /whitelist add <player>');
                    return false;
                }
                $lists->addWhitelist($name);
                $sender->sendMessage("Added $name to the whitelist.");
                return true;
            case 'remove':
            case 'del':
                $name = array_shift($args);
                if ($name === null || $name === '') {
                    $sender->sendMessage('Usage: /whitelist remove <player>');
                    return false;
                }
                $lists->removeWhitelist($name);
                $sender->sendMessage("Removed $name from the whitelist.");
                return true;
            case 'list':
                $entries = $lists->getWhitelist();
                $sender->sendMessage($entries === []
                    ? 'The whitelist is empty.'
                    : 'Whitelisted: ' . implode(', ', $entries));
                return true;
            default:
                $sender->sendMessage('Usage: /whitelist <on|off|add|remove|list> [player]');
                return false;
        }
    }

    private function setWhitelistEnabled(bool $enabled): void {
        $kernel = $this->kernel();
        if ($kernel === null) {
            return;
        }
        $config = $kernel->getResourceRegistry()->get(\pocketmine\core\resource\ServerConfig::class);
        if ($config instanceof \pocketmine\core\resource\ServerConfig) {
            $config->whiteList = $enabled;
        }
        \pocketmine\api\server\Server::getInstance()->setWhitelist($enabled);
    }
}

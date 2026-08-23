<?php

declare(strict_types=1);

namespace pocketmine\api\command\builtin;

use pocketmine\api\command\CommandSender;
use pocketmine\core\component\MetadataComponent;

/**
 * /deop <player> — revoke operator status (ops.txt + live metadata).
 */
final class DeopCommand extends BuiltinCommand {
    public function __construct() {
        parent::__construct(
            'deop',
            'Revoke operator status from a player',
            '/deop <player>',
            [],
            'khronos.command.deop',
            category: 'admin',
        );
    }

    public function execute(CommandSender $sender, array $args): bool {
        $name = array_shift($args);
        if ($name === null || $name === '') {
            $sender->sendMessage('Usage: /deop <player>');
            return false;
        }
        $lists = $this->playerLists();
        if ($lists === null) {
            return false;
        }
        $lists->removeOp($name);
        $kernel = $this->kernel();
        if ($kernel !== null) {
            $id = $this->findOnlineId($name);
            if ($id !== null) {
                $meta = $kernel->getWorld()->getEntity($id)?->get(MetadataComponent::class);
                if ($meta !== null) {
                    $perms = (array)$meta->get('permissions', []);
                    $perms = array_values(array_diff($perms, ['pocketmine.op', 'op']));
                    $meta->set('permissions', $perms);
                }
            }
        }
        $sender->sendMessage("Deopped $name");
        return true;
    }
}

<?php

declare(strict_types=1);

namespace pocketmine\api\command\builtin;

use pocketmine\api\command\CommandSender;
use pocketmine\core\component\MetadataComponent;

/**
 * /op <player> — grant operator status. Persisted to ops.txt immediately and
 * applied to the online player's entity metadata so every permission check
 * (builtin + plugin) sees the grant.
 */
final class OpCommand extends BuiltinCommand {
    public function __construct() {
        parent::__construct(
            'op',
            'Grant operator status to a player',
            '/op <player>',
            [],
            'khronos.command.op',
        );
    }

    public function execute(CommandSender $sender, array $args): bool {
        $name = array_shift($args);
        if ($name === null || $name === '') {
            $sender->sendMessage('Usage: /op <player>');
            return false;
        }
        $lists = $this->playerLists();
        if ($lists === null) {
            return false;
        }
        $lists->addOp($name);
        $this->grantOnline($name);
        $sender->sendMessage("Opped $name");
        return true;
    }

    private function grantOnline(string $name): void {
        $kernel = $this->kernel();
        if ($kernel === null) {
            return;
        }
        $id = $this->findOnlineId($name);
        if ($id === null) {
            return; // offline: ops.txt alone carries the grant until they join
        }
        $meta = $kernel->getWorld()->getEntity($id)?->get(MetadataComponent::class);
        if ($meta === null) {
            return;
        }
        $perms = (array)$meta->get('permissions', []);
        if (!in_array('pocketmine.op', $perms, true)) {
            $perms[] = 'pocketmine.op';
            $meta->set('permissions', $perms);
        }
    }
}

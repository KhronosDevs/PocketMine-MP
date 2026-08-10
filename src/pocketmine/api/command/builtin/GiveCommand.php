<?php

declare(strict_types=1);

namespace pocketmine\api\command\builtin;

use pocketmine\api\command\CommandSender;
use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\ItemStack;
use pocketmine\core\resource\ItemRegistry;

/**
 * /give <player> <item> [count] — grants an item stack to a player's
 * inventory (window-0 slots only; armor slots are never auto-filled) and
 * syncs the window back to the client.
 */
final class GiveCommand extends BuiltinCommand {
    public function __construct() {
        parent::__construct(
            'give',
            'Give an item to a player',
            '/give <player> <item> [count]',
            [],
        );
    }

    public function execute(CommandSender $sender, array $args): bool {
        $targetName = array_shift($args) ?? null;
        $itemArg = array_shift($args) ?? '';
        $countArg = array_shift($args) ?? '1';

        $targetId = $this->resolvePlayerId($sender, $targetName);
        if ($targetId === null) {
            $sender->sendMessage('Player not found.');
            return false;
        }
        if (!ctype_digit($itemArg)) {
            $sender->sendMessage('Item must be a numeric id.');
            return false;
        }
        $itemId = (int)$itemArg;
        if ($itemId < 1 || $itemId > 436) {
            $sender->sendMessage("Unknown item id $itemId.");
            return false;
        }
        $count = max(1, min(2304, (int)$countArg)); // 64 * 36 window-0 slots

        $kernel = $this->kernel();
        if ($kernel === null) {
            return false;
        }
        $inventory = $kernel->getWorld()->getEntity($targetId)?->get(InventoryComponent::class);
        if ($inventory === null) {
            return false;
        }

        $item = new ItemStack($itemId, 0, $count);
        if (!$inventory->add($item)) {
            $sender->sendMessage('Inventory is full.');
            return false;
        }

        $kernel->getNetworkSessionService()->syncInventoryContents($targetId);

        $registry = $kernel->getWorld()->getResourceRegistry()->get(ItemRegistry::class);
        $name = $registry instanceof ItemRegistry ? $registry->getName($itemId) : "item $itemId";
        $target = $this->playerName($targetId);
        $sender->sendMessage("Gave $count x $name to $target.");
        return true;
    }
}

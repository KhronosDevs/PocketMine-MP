<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

use pocketmine\core\component\InventoryComponent;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\ecs\EntityRef;
use pocketmine\Kernel;

/**
 * Tool wear (survival durability). One point of wear is consumed per block
 * broken or attack landed while the player holds a durable item; the item's
 * meta doubles as its damage counter (legacy parity). When the counter
 * reaches the item's max durability the tool breaks and the slot empties.
 *
 * No-op for creative players, bare hands, and non-durable items. Shared by
 * BlockBreakService (mining) and EntityInteractionService (combat) so both
 * wear paths stay identical.
 */
final class ItemDurability {

    public static function consume(EntityRef $playerRef): void {
        $player = $playerRef->getEntity();
        if ($player === null) {
            return;
        }
        $metadata = $player->get(MetadataComponent::class);
        if (\pocketmine\core\enum\GameMode::coerce($metadata?->get(\pocketmine\core\constants\MetadataKeys::GAMEMODE)) === \pocketmine\core\enum\GameMode::Creative) {
            return; // creative: no wear
        }
        $inventory = $player->get(InventoryComponent::class);
        if ($inventory === null) {
            return;
        }
        $slot = $inventory->heldSlot;
        $item = $inventory->get($slot);
        if ($item === null || $item->count <= 0) {
            return;
        }

        $kernel = Kernel::getInstance();
        $registry = $kernel?->getResourceRegistry()->get(ItemRegistry::class);
        $max = $registry instanceof ItemRegistry ? $registry->getMaxDurability($item->itemId) : 0;
        if ($max <= 0) {
            return; // not a durable tool
        }
        // Armor pieces (ids 298-317) are durable but only wear when worn and
        // taking damage - never from being held while mining or attacking.
        if ($item->itemId >= 298 && $item->itemId <= 317) {
            return;
        }

        // 14.30: Unbreaking gives a (1 - 1/(level+1)) chance to skip the wear
        // (legacy Item::applyDamage / Unbreaking check).
        $unbreaking = $item->getEnchantmentLevel(17);
        if ($unbreaking > 0 && mt_rand(0, $unbreaking) !== 0) {
            return; // the wear was absorbed
        }

        $item->meta++;
        if ($item->meta >= $max) {
            $inventory->set($slot, null); // the tool broke
        }
        $kernel?->getNetworkSessionService()->syncInventorySlot($playerRef->entityId, $slot);
    }
}

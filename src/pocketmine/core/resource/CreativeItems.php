<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

/**
 * The creative inventory shown in the client's item picker (window 0x79).
 * Entries are wire-ready [id, count, meta, nbt] triples (count 1 - the client
 * hands out full stacks on pick).
 *
 * The list is the exact MCPE 0.15.10 creative-items table (ported verbatim from
 * the legacy PocketMine creativeitems.json, 566 entries). It must stay EXACTLY
 * the legacy list: the 0.15 client's CraftingContainerManagerModel::init()
 * categorises every received creative item (Block::getCreativeCategory for
 * blocks, a NULL item-registry dereference at Item*+0x28 otherwise) and SIGSEGVs
 * on any id/metadata the client does not know. A curated subset crashes the
 * inventory/crafting screen; the full vanilla list is the only verified-safe set.
 */
final class CreativeItems {

    /**
     * @return list<array{0: int, 1: int, 2: int, 3: ?string}>
     */
    public static function all(): array {
        $raw = json_decode((string)file_get_contents(__DIR__ . '/creativeitems.json'), true);
        $items = [];
        foreach (($raw ?: []) as $entry) {
            $items[] = [(int)$entry['ID'], 1, (int)$entry['Damage'], null];
        }
        return $items;
    }
}
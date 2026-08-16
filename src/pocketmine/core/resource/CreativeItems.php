<?php

declare(strict_types=1);

namespace pocketmine\core\resource;

/**
 * The creative inventory shown in the client's item picker (window 0x79).
 * Entries are wire-ready [id, count, meta, nbt] triples (count 1 - the client
 * hands out full stacks on pick). The list is a curated vanilla subset that
 * matches the protocol-84 item ids; the full legacy CreativeItems table can
 * be ported later without changing the wire format.
 */
final class CreativeItems {

    /**
     * @return list<array{0: int, 1: int, 2: int, 3: ?string}>
     */
    public static function all(): array {
        $items = [];

        // --- Building blocks ---
        $blocks = [
            [1, 0], [1, 1], [1, 2], [1, 3], [1, 4], [1, 5], [1, 6], // stone variants
            [2, 0], // grass
            [3, 0], // dirt
            [4, 0], // cobblestone
            [5, 0], [5, 1], [5, 2], [5, 3], [5, 4], [5, 5], // planks
            [12, 0], [12, 1], // sand
            [13, 0], // gravel
            [14, 0], // gold ore
            [15, 0], // iron ore
            [16, 0], // coal ore
            [17, 0], [17, 1], [17, 2], [17, 3], // logs
            [18, 0], [18, 1], [18, 2], [18, 3], // leaves
            [20, 0], // glass
            [24, 0], // sandstone
            [35, 0], [35, 1], [35, 2], [35, 3], [35, 4], [35, 5], [35, 6], [35, 7],
            [35, 8], [35, 9], [35, 10], [35, 11], [35, 12], [35, 13], [35, 14], [35, 15], // wool
            [45, 0], // brick block
            [46, 0], // TNT
            [48, 0], // moss stone
            [49, 0], // obsidian
            [50, 0], // torch
            [54, 0], // chest
            [56, 0], // diamond ore
            [57, 0], // diamond block
            [58, 0], // crafting table
            [61, 0], // furnace
            [73, 0], // redstone ore
            [79, 0], // ice
            [82, 0], // clay block
            [87, 0], // netherrack
            [89, 0], // glowstone
            [98, 0], [98, 1], [98, 2], [98, 3], // stone bricks
            [103, 0], // melon block
            [110, 0], // mycelium
            [112, 0], // nether brick
            [129, 0], // emerald ore
            [133, 0], // emerald block
            [153, 0], // nether quartz ore
            [162, 0], [162, 1], // acacia/dark oak logs
            [172, 0], // hardened clay
        ];

        // --- Tools / weapons ---
        $tools = [
            [256, 0], // iron shovel
            [257, 0], // iron pickaxe
            [258, 0], // iron axe
            [267, 0], // iron sword
            [268, 0], // wooden sword
            [269, 0], // wooden shovel
            [270, 0], // wooden pickaxe
            [271, 0], // wooden axe
            [272, 0], // stone sword
            [273, 0], // stone shovel
            [274, 0], // stone pickaxe
            [275, 0], // stone axe
            [276, 0], // diamond sword
            [277, 0], // diamond shovel
            [278, 0], // diamond pickaxe
            [279, 0], // diamond axe
            [283, 0], // gold sword
            [284, 0], // gold shovel
            [285, 0], // gold pickaxe
            [286, 0], // gold axe
            [290, 0], // wooden hoe
            [291, 0], // stone hoe
            [292, 0], // iron hoe
            [293, 0], // diamond hoe
            [294, 0], // gold hoe
            [261, 0], // bow
            [262, 0], // arrow
            [298, 0], [299, 0], [300, 0], [301, 0], // leather armor
            [302, 0], [303, 0], [304, 0], [305, 0], // chainmail armor
            [306, 0], [307, 0], [308, 0], [309, 0], // iron armor
            [310, 0], [311, 0], [312, 0], [313, 0], // diamond armor
            [314, 0], [315, 0], [316, 0], [317, 0], // gold armor
            [359, 0], // shears
            [346, 0], // fishing rod
            [259, 0], // flint & steel
            [325, 0], // bucket
        ];

        // --- Materials ---
        $materials = [
            [263, 0], [263, 1], // coal, charcoal
            [264, 0], // diamond
            [265, 0], // iron ingot
            [266, 0], // gold ingot
            [280, 0], // stick
            [287, 0], // string
            [288, 0], // feather
            [289, 0], // gunpowder
            [331, 0], // redstone
            [332, 0], // snowball
            [334, 0], // leather
            [336, 0], // brick
            [337, 0], // clay ball
            [341, 0], // slime ball
            [344, 0], // egg
            [348, 0], // glowstone dust
            [351, 0], [351, 1], [351, 2], [351, 3], [351, 4], [351, 5], [351, 6], [351, 7],
            [351, 8], [351, 9], [351, 10], [351, 11], [351, 12], [351, 13], [351, 14], [351, 15], // dyes
            [352, 0], // bone
            [353, 0], // sugar
            [368, 0], // ender pearl
            [369, 0], // blaze rod
            [388, 0], // emerald
            [406, 0], // quartz
            [328, 0], // minecart
            [333, 0], // boat
            [323, 0], // sign
            [389, 0], // item frame
        ];

        // --- Food ---
        $food = [
            [260, 0], // apple
            [282, 0], // mushroom stew
            [297, 0], // bread
            [319, 0], // raw porkchop
            [320, 0], // cooked porkchop
            [349, 0], // raw fish
            [350, 0], // cooked fish
            [357, 0], // cookie
            [360, 0], // melon slice
            [363, 0], // raw beef
            [364, 0], // steak
            [365, 0], // raw chicken
            [366, 0], // cooked chicken
            [367, 0], // rotten flesh
            [392, 0], // potato
            [393, 0], // baked potato
            [396, 0], // golden carrot
            [397, 0], // golden apple
        ];

        foreach ([$blocks, $tools, $materials, $food] as $group) {
            foreach ($group as [$id, $meta]) {
                $items[] = [$id, 1, $meta, null];
            }
        }

        return $items;
    }
}

<?php

declare(strict_types=1);

namespace pocketmine\protocol;

use pocketmine\core\component\ItemStack;
use pocketmine\utils\BinaryStream;
use pocketmine\utils\UUID;
use function array_map;
use function count;
use function max;
use function strlen;

/**
 * Protocol 84 server->client: the recipe list the client uses to render the
 * crafting UI and to compute craft results locally. Legacy 0x2f layout:
 *
 *   putInt(entryCount)
 *   per entry: putInt(entryType), putInt(payloadLen), payload
 *   putByte(cleanRecipes ? 1 : 0)
 *
 * Shaped entry payload: width, height, width*height ingredient slots,
 * putInt(1) result count, result slot, recipe UUID.
 */
class CraftingDataPacket extends DataPacket {
    const NETWORK_ID = Info::CRAFTING_DATA_PACKET;

    const ENTRY_SHAPELESS = 0;
    const ENTRY_SHAPED = 1;

    /**
     * @var array<string, array{pattern: list<string>, key: array<string, ItemStack>, result: ItemStack}>
     */
    public array $recipes = [];

    public function decode(): void {
        // server->client only
    }

    public function encode(): void {
        $this->reset();
        $this->putInt(count($this->recipes));

        foreach ($this->recipes as $id => $recipe) {
            $writer = new BinaryStream();
            $width = max(array_map('strlen', $recipe['pattern']));
            $height = count($recipe['pattern']);

            $writer->putInt($width);
            $writer->putInt($height);

            // Row-major grid: the client renders these width*height slots in
            // the order received, so row outer / column inner matches the
            // pattern strings as written.
            for ($row = 0; $row < $height; $row++) {
                for ($col = 0; $col < $width; $col++) {
                    $char = $recipe['pattern'][$row][$col] ?? ' ';
                    $ing = $recipe['key'][$char] ?? null;
                    if ($char === ' ' || $ing === null) {
                        $writer->putSlot([0, 0, 0, null]);
                        continue;
                    }
                    $meta = $ing->meta === -1 ? 32767 : $ing->meta; // wildcard damage
                    $writer->putSlot([$ing->itemId, 1, $meta, null]);
                }
            }

            $writer->putInt(1); // result count
            $writer->putSlot([$recipe['result']->itemId, $recipe['result']->count, $recipe['result']->meta, null]);
            $writer->putUUID(UUID::fromData($id));

            $this->putInt(self::ENTRY_SHAPED);
            $this->putInt(strlen($writer->getBuffer()));
            $this->put($writer->getBuffer());
        }

        $this->putByte(0); // cleanRecipes
    }
}

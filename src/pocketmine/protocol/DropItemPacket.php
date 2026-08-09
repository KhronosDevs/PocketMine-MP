<?php

declare(strict_types=1);

namespace pocketmine\protocol;

/**
 * Protocol 84 client->server: the player pressed Q (drop item) with an item
 * in hand. Legacy layout (old-src DropItemPacket): a type byte (0 = drop from
 * hand, 1 = drop from inventory UI) followed by the item slot being dropped
 * (id/count/damage/nbt). The count field is how many the client wants to drop
 * (normally 1 for a single Q press).
 */
class DropItemPacket extends DataPacket {
    const NETWORK_ID = Info::DROP_ITEM_PACKET;

    public int $type = 0;
    /** @var array{0: int, 1: int, 2: int, 3: ?string} [id, count, damage, nbt] */
    public array $item = [0, 0, 0, null];

    public function decode(): void {
        $this->type = $this->getByte();
        $this->item = $this->getSlot();
    }

    public function encode(): void {
        // Mirrors decode so the test client can send this packet over the
        // wire; the server only ever decodes it.
        $this->reset();
        $this->putByte($this->type);
        $this->putSlot($this->item);
    }
}

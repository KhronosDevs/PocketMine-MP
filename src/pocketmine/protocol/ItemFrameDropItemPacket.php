<?php

declare(strict_types=1);

namespace pocketmine\protocol;

/**
 * Protocol 84 client->server: the player right-clicked an item frame to drop
 * its contents. Legacy layout (old-src ItemFrameDropItemPacket): z, y, x
 * (note the order!) followed by the item slot currently in the frame
 * (id/count/damage/nbt) - the server validates against its authoritative
 * state and drops the real item if the claim matches.
 */
class ItemFrameDropItemPacket extends DataPacket {
    const NETWORK_ID = Info::ITEM_FRAME_DROP_ITEM_PACKET;

    public int $x = 0;
    public int $y = 0;
    public int $z = 0;
    /** @var array{0: int, 1: int, 2: int, 3: ?string} [id, count, damage, nbt] */
    public array $item = [0, 0, 0, null];

    public function decode(): void {
        $this->z = $this->getInt();
        $this->y = $this->getInt();
        $this->x = $this->getInt();
        $this->item = $this->getSlot();
    }

    public function encode(): void {
        // Mirrors decode so the test client can send this packet over the
        // wire; the server only ever decodes it.
        $this->reset();
        $this->putInt($this->z);
        $this->putInt($this->y);
        $this->putInt($this->x);
        $this->putSlot($this->item);
    }
}

<?php

declare(strict_types=1);

namespace pocketmine\protocol;

/**
 * Protocol 84 client->server: the player used the item in hand (place block,
 * use item). Field layout matches the legacy old-src UseItemPacket exactly.
 */
class UseItemPacket extends DataPacket {
    const NETWORK_ID = Info::USE_ITEM_PACKET;

    public int $x = 0; // clicked block x
    public int $y = 0; // clicked block y
    public int $z = 0; // clicked block z
    public int $face = 0;
    /** @var array{0: int, 1: int, 2: int, 3: ?string} [id, count, damage, nbt] */
    public array $item = [0, 0, 0, null];
    public float $fx = 0.0;
    public float $fy = 0.0;
    public float $fz = 0.0;
    public float $posX = 0.0;
    public float $posY = 0.0;
    public float $posZ = 0.0;
    public int $slot = 0;

    public function decode(): void {
        $this->x = $this->getInt();
        $this->y = $this->getInt();
        $this->z = $this->getInt();
        $this->face = $this->getByte();
        $this->fx = $this->getFloat();
        $this->fy = $this->getFloat();
        $this->fz = $this->getFloat();
        $this->posX = $this->getFloat();
        $this->posY = $this->getFloat();
        $this->posZ = $this->getFloat();
        $this->slot = $this->getInt();
        $this->item = $this->getSlot();
    }

    public function encode(): void {
        // Mirrors decode so the test client (and any tooling) can send this
        // packet over the wire; the server only ever decodes it.
        $this->reset();
        $this->putInt($this->x);
        $this->putInt($this->y);
        $this->putInt($this->z);
        $this->putByte($this->face);
        $this->putFloat($this->fx);
        $this->putFloat($this->fy);
        $this->putFloat($this->fz);
        $this->putFloat($this->posX);
        $this->putFloat($this->posY);
        $this->putFloat($this->posZ);
        $this->putInt($this->slot);
        $this->putSlot($this->item);
    }
}

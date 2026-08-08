<?php

declare(strict_types=1);

namespace pocketmine\protocol;

/**
 * Protocol 84 held-item change: the client tells the server which hotbar slot
 * is selected (and what item is in it); the server echoes it back to sync
 * other players' view of the held item. Layout matches the legacy
 * old-src MobEquipmentPacket.
 */
class MobEquipmentPacket extends DataPacket {
    const NETWORK_ID = Info::MOB_EQUIPMENT_PACKET;

    public int $eid = 0;
    /** @var array{0: int, 1: int, 2: int, 3: ?string} [id, count, damage, nbt] */
    public array $item = [0, 0, 0, null];
    public int $slot = 0;
    public int $selectedSlot = 0;

    public function decode(): void {
        $this->eid = $this->getLong();
        $this->item = $this->getSlot();
        $this->slot = $this->getByte();
        $this->selectedSlot = $this->getByte();
    }

    public function encode(): void {
        $this->reset();
        $this->putLong($this->eid);
        $this->putSlot($this->item);
        $this->putByte($this->slot);
        $this->putByte($this->selectedSlot);
    }
}

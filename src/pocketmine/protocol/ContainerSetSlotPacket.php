<?php

declare(strict_types=1);

namespace pocketmine\protocol;

/**
 * Protocol 84 server->client: update a single slot of a window (window 0 =
 * the player's own inventory). Used to keep the client in sync when a placed
 * block consumed one item from the held stack. Legacy layout.
 */
class ContainerSetSlotPacket extends DataPacket {
    const NETWORK_ID = Info::CONTAINER_SET_SLOT_PACKET;

    public int $windowid = 0;
    public int $slot = 0;
    /** @var array{0: int, 1: int, 2: int, 3: ?string} [id, count, damage, nbt] */
    public array $item = [0, 0, 0, null];
    public int $hotbarSlot = 0;

    public function decode(): void {
        // server->client only
    }

    public function encode(): void {
        $this->reset();
        $this->putByte($this->windowid);
        $this->putShort($this->slot);
        $this->putShort($this->hotbarSlot);
        $this->putSlot($this->item);
    }
}

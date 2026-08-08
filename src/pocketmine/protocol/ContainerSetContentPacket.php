<?php

declare(strict_types=1);

namespace pocketmine\protocol;

/**
 * Protocol 84 server->client: send the full contents of a window (window 0 =
 * the player's own inventory) so the client can render its hotbar/inventory.
 * Layout matches the legacy old-src ContainerSetContentPacket; the trailing
 * hotbar section is emitted as an empty list (count 0).
 */
class ContainerSetContentPacket extends DataPacket {
    const NETWORK_ID = Info::CONTAINER_SET_CONTENT_PACKET;

    const SPECIAL_INVENTORY = 0;
    const SPECIAL_ARMOR = 0x78;
    const SPECIAL_CREATIVE = 0x79;
    const SPECIAL_HOTBAR = 0x7a;

    public int $windowid = 0;
    /** @var list<array{0: int, 1: int, 2: int, 3: ?string}> */
    public array $slots = [];

    public function decode(): void {
        // server->client only
    }

    public function encode(): void {
        $this->reset();
        $this->putByte($this->windowid);
        $this->putShort(count($this->slots));
        foreach ($this->slots as $slot) {
            $this->putSlot($slot);
        }
        // Hotbar section (only meaningful for the player inventory window);
        // legacy sends an empty list when no mapping is present.
        $this->putShort(0);
    }
}

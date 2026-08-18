<?php

declare(strict_types=1);

namespace pocketmine\protocol;

/**
 * Protocol 84 server->client: send the full contents of a window (window 0 =
 * the player's own inventory) so the client can render its hotbar/inventory.
 *
 * Layout matches the legacy old-src ContainerSetContentPacket exactly:
 * - 45 slots for window 0 (36 real + 9 dummy air — the 0.15 PE client
 *   "shows 9 less slots than you send it", so the dummies are required).
 * - A trailing hotbar section (9 ints mapping hotbar slots to inventory
 *   indices) for window 0 only. Without this mapping the client renders
 *   an empty or broken inventory when E is pressed.
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
    /** @var int[] Hotbar slot mapping (window 0 only; empty for other windows). */
    public array $hotbar = [];

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
        // Hotbar section: the 0.15 client expects a mapping for window 0
        // (9 ints, each the inventory index for that hotbar slot). Legacy
        // sends an empty list when no mapping is present (other windows).
        if ($this->windowid === self::SPECIAL_INVENTORY && count($this->hotbar) > 0) {
            $this->putShort(count($this->hotbar));
            foreach ($this->hotbar as $slot) {
                $this->putInt($slot);
            }
        } else {
            $this->putShort(0);
        }
    }
}

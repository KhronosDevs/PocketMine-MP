<?php

declare(strict_types=1);

namespace pocketmine\protocol;

/**
 * Protocol 84 client->server: the client asks for the current texture of a
 * map item (0x3c), typically when a filled map is selected in the hotbar.
 * Body: svarint mapId (entity unique id, signed varint — PMMP 1.6.2 parity).
 */
class MapInfoRequestPacket extends DataPacket {
    const NETWORK_ID = Info::MAP_INFO_REQUEST_PACKET;

    /** @var int */
    public $mapId = 0;

    public function decode(): void {
        $this->mapId = $this->getVarInt();
    }

    public function encode(): void {
        $this->reset();
        $this->putVarInt($this->mapId);
    }
}

<?php

declare(strict_types=1);

namespace pocketmine\protocol;

/**
 * Protocol 84 client->server: the client asks for the current texture of a
 * map item (0x3c), typically when a filled map is selected in the hotbar.
 * Body: long mapId (entity unique id).
 */
class MapInfoRequestPacket extends DataPacket {
    const NETWORK_ID = Info::MAP_INFO_REQUEST_PACKET;

    /** @var int */
    public $mapId = 0;

    public function decode(): void {
        $this->mapId = $this->getLong();
    }

    public function encode(): void {
        $this->reset();
        $this->putLong($this->mapId);
    }
}

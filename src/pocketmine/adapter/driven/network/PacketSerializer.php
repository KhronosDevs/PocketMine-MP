<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\network;

use pocketmine\protocol\DataPacket;

final class PacketSerializer {
    public function serialize(DataPacket $packet): string {
        $packet->encode();
        return $packet->getBuffer();
    }

    public function deserialize(string $data, int $packetId): ?DataPacket {
        // TODO: Use Network::getPacket() to create packet instance
        return null;
    }
}
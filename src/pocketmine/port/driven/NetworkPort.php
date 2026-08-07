<?php

declare(strict_types=1);

namespace pocketmine\port\driven;

interface NetworkPort {
    public function sendPacket(PlayerRef $player, \pocketmine\protocol\DataPacket $packet): void;

    public function broadcastPacket(iterable $players, \pocketmine\protocol\DataPacket $packet): void;

    public function disconnect(PlayerRef $player, string $reason): void;

    public function processPendingCommands(): void;

    public function flushOutboundPackets(): void;
}
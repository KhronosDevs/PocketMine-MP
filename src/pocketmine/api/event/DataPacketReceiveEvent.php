<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Player;

/**
 * Fires for every game packet received from a player (after login - the
 * session must exist). Cancelling drops the packet before it is handled.
 * The packet id is the wire id (see pocketmine\protocol\Info constants);
 * the raw buffer starts with the id byte followed by the packet body.
 */
class DataPacketReceiveEvent extends CancellableEvent {
    public function __construct(
        public readonly Player $player,
        public readonly int $packetId,
        public readonly string $buffer,
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }

    public function getPacketId(): int {
        return $this->packetId;
    }

    public function getBuffer(): string {
        return $this->buffer;
    }
}

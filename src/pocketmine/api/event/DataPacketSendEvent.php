<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Player;
use pocketmine\protocol\DataPacket;

/**
 * Fires for every game packet queued to a player. Cancelling drops the
 * packet before it is sent. Plugins can also mutate the packet object in
 * place before it goes out (e.g. rewrite chat text).
 */
class DataPacketSendEvent extends CancellableEvent {
    public function __construct(
        public readonly Player $player,
        public readonly DataPacket $packet,
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }

    public function getPacket(): DataPacket {
        return $this->packet;
    }
}

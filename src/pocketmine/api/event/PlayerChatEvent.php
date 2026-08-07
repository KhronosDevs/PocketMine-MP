<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Player;
use pocketmine\api\entity\Entity;
use pocketmine\api\block\Block;

class PlayerChatEvent extends CancellableEvent {
    public function __construct(
        public readonly Player $player,
        public string $message,
    ) {}

    public function getPlayer(): Player {
        return $this->player;
    }

    public function getMessage(): string {
        return $this->message;
    }

    public function setMessage(string $message): void {
        $this->message = $message;
    }
}

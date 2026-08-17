<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\command\CommandSender;

/**
 * Fires when a command is executed by any sender (console or player, via
 * the command map). Cancelling blocks the command. Player commands also
 * fire PlayerCommandPreprocessEvent first.
 */
class ServerCommandEvent extends CancellableEvent {
    public function __construct(
        public readonly CommandSender $sender,
        public string $command,
    ) {}

    public function getSender(): CommandSender {
        return $this->sender;
    }

    public function getCommand(): string {
        return $this->command;
    }

    public function setCommand(string $command): void {
        $this->command = $command;
    }
}

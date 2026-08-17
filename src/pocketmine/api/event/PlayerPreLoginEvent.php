<?php

declare(strict_types=1);

namespace pocketmine\api\event;

/**
 * Fires when a client sends a LoginPacket, BEFORE the player entity is
 * created - the earliest point a plugin can veto a connection (IP bans,
 * whitelists, player-cap plugins that want to reject by name alone).
 *
 * Unlike PlayerLoginEvent there is no Player object yet (the ECS entity
 * does not exist at this stage), so the event carries the identity fields
 * from the login packet. Cancelling disconnects the client with the kick
 * message set via setKickMessage() (default: "Login cancelled").
 */
class PlayerPreLoginEvent extends CancellableEvent {
    public function __construct(
        public readonly string $username,
        public readonly string $address,
        private string $kickMessage = 'Login cancelled',
    ) {}

    public function getUsername(): string {
        return $this->username;
    }

    public function getAddress(): string {
        return $this->address;
    }

    public function getKickMessage(): string {
        return $this->kickMessage;
    }

    public function setKickMessage(string $kickMessage): void {
        $this->kickMessage = $kickMessage;
    }
}

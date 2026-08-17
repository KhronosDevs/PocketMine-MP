<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Entity;

/**
 * Fires when an entity teleports (command, respawn, dimension travel).
 * Cancelling keeps the entity at its old position.
 */
class EntityTeleportEvent extends CancellableEvent {
    /** @param array{0: float, 1: float, 2: float} $from */
    /** @param array{0: float, 1: float, 2: float} $to */
    public function __construct(
        public readonly Entity $entity,
        public readonly array $from,
        public array $to,
    ) {}

    public function getEntity(): Entity {
        return $this->entity;
    }

    public function getFrom(): array {
        return $this->from;
    }

    public function getTo(): array {
        return $this->to;
    }

    public function setTo(array $to): void {
        $this->to = $to;
    }
}

<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Player;
use pocketmine\api\entity\Entity;
use pocketmine\api\block\Block;

class EntityDeathEvent extends Event {
    public function __construct(
        public readonly Entity $entity,
        public readonly ?Entity $killer = null,
    ) {}

    public function getEntity(): Entity {
        return $this->entity;
    }

    public function getKiller(): ?Entity {
        return $this->killer;
    }
}

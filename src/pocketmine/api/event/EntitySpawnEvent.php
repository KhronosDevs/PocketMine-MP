<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\entity\Player;
use pocketmine\api\entity\Entity;
use pocketmine\api\block\Block;

class EntitySpawnEvent extends Event {
    public function __construct(
        public readonly Entity $entity,
    ) {}

    public function getEntity(): Entity {
        return $this->entity;
    }
}

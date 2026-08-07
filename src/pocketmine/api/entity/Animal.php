<?php

declare(strict_types=1);

namespace pocketmine\api\entity;

use pocketmine\domain\ecs\EntityRef;
use pocketmine\domain\ecs\World;
use pocketmine\domain\component\MetadataComponent;

abstract class Animal extends Living {
    public function __construct(EntityRef $ref, \pocketmine\domain\ecs\World $world) {
        parent::__construct($ref, $world);
        // Ensure Animal-specific metadata
    }

    public function getAge(): int {
        $metadata = $this->getMetadata();
        return $metadata->get('age', 0);
    }

    public function setAge(int $age): void {
        $metadata = $this->getMetadata();
        $metadata->set('age', $age);
    }

    public function isBaby(): bool {
        return $this->getAge() < 0;
    }

    public function setBaby(bool $baby): void {
        $this->setAge($baby ? -1 : 0);
    }

    public function getLoveTimer(): int {
        $metadata = $this->getMetadata();
        return $metadata->get('loveTimer', 0);
    }

    public function setLoveTimer(int $ticks): void {
        $metadata = $this->getMetadata();
        $metadata->set('loveTimer', $ticks);
    }

    public function canBreed(): bool {
        return $this->getLoveTimer() <= 0 && $this->isAdult();
    }

    public function isAdult(): bool {
        return !$this->isBaby();
    }

    public function breed(\pocketmine\api\entity\Animal $partner): ?self {
        // Breeding logic would be implemented here
        return null;
    }

    public function setOwner(\pocketmine\api\entity\Player $player): void {
        $metadata = $this->getMetadata();
        $metadata->set('owner', $player->getUniqueId());
    }

    public function getOwner(): ?\pocketmine\api\entity\Player {
        $metadata = $this->getMetadata();
        $ownerId = $metadata->get('owner');
        if ($ownerId) {
            // Would need to find player by uniqueId
            return null;
        }
        return null;
    }

    public function isTamed(): bool {
        $metadata = $this->getMetadata();
        return $metadata->get('tamed', false);
    }

    public function setTamed(bool $tamed): void {
        $metadata = $this->getMetadata();
        $metadata->set('tamed', $tamed);
    }
}
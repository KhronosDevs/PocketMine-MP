<?php

declare(strict_types=1);

namespace pocketmine\api\entity;

use pocketmine\domain\ecs\EntityRef;
use pocketmine\domain\ecs\World;
use pocketmine\domain\component\MetadataComponent;

class Zombie extends Monster {
    public function __construct(EntityRef $ref, \pocketmine\domain\ecs\World $world) {
        parent::__construct($ref, $world);
    }

    public static function create(float $x, float $y, float $z): self {
        $kernel = \pocketmine\Kernel::getInstance();
        $spawnService = $kernel->getEntitySpawnService();
        
        $entityRef = $spawnService->spawnEntity('Zombie', $x, $y, $z);
        
        $kernel = \pocketmine\Kernel::getInstance();
        $world = $kernel->getWorld();
        
        return new self($entityRef, $world);
    }
}
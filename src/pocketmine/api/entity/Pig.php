<?php

declare(strict_types=1);

namespace pocketmine\api\entity;

use pocketmine\core\ecs\EntityRef;
use pocketmine\core\ecs\World;

class Pig extends Animal {
    public function __construct(EntityRef $ref, \pocketmine\core\ecs\World $world) {
        parent::__construct($ref, $world);
    }

    public static function create(float $x, float $y, float $z): self {
        $kernel = \pocketmine\Kernel::getInstance();
        $spawnService = $kernel->getEntitySpawnService();
        
        $entityRef = $spawnService->spawnEntity(\pocketmine\core\enum\EntityType::Pig, func_get_arg(0), func_get_arg(1), func_get_arg(2));
        
        $kernel = \pocketmine\Kernel::getInstance();
        $world = $kernel->getWorld();
        
        return new self($entityRef, $world);
    }
}
<?php

declare(strict_types=1);

namespace pocketmine\core\ecs;

interface System {
    public function run(World $world, float $deltaTime): void;
}
<?php

declare(strict_types=1);

namespace pocketmine\domain\ecs;

interface System {
    public function run(World $world, float $deltaTime): void;
}
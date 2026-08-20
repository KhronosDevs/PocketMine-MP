<?php

declare(strict_types=1);

namespace pocketmine\domain\ecs;

interface ParallelSystem extends System {
    public function runParallel(Archetype $archetype, float $deltaTime): void;

    public function getTargetArchetypes(World $world): iterable;
}
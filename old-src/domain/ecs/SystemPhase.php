<?php

declare(strict_types=1);

namespace pocketmine\domain\ecs;

enum SystemPhase: int {
    case SEQUENTIAL = 0;
    case PARALLEL = 1;
    case CHUNK_PARALLEL = 2;
}
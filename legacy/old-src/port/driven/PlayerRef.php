<?php

declare(strict_types=1);

namespace pocketmine\port\driven;

final class PlayerRef {
    public function __construct(
        public readonly string $uniqueId,
        public readonly int $entityId,
        public readonly string $name,
    ) {}
}
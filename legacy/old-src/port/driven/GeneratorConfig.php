<?php

declare(strict_types=1);

namespace pocketmine\port\driven;

final class GeneratorConfig {
    public function __construct(
        public readonly string $generatorType,
        public readonly int $seed,
        public readonly array $options,
    ) {}
}
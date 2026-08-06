<?php

declare(strict_types=1);

namespace pocketmine\port\driving;

final class PluginDescription {
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $author,
        public readonly array $depend = [],
        public readonly array $softDepend = [],
        public readonly array $commands = [],
        public readonly array $permissions = [],
    ) {}
}
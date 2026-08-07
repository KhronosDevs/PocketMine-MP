<?php

declare(strict_types=1);

namespace pocketmine\api\permission;

class Permission {
    public const DEFAULT_OP = 1;
    public const DEFAULT_NOT_OP = 2;
    public const DEFAULT_TRUE = 3;
    public const DEFAULT_FALSE = 4;

    public function __construct(
        public readonly string $name,
        public readonly string $description = "",
        public readonly int $default = self::DEFAULT_NOT_OP,
        public readonly array $children = [],
    ) {}

    public function getName(): string {
        return $this->name;
    }

    public function getDescription(): string {
        return $this->description;
    }

    public function getDefault(): int {
        return $this->default;
    }

    public function getChildren(): array {
        return $this->children;
    }
}

<?php

declare(strict_types=1);

namespace pocketmine\plugin\api\command;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class CommandName {
    public function __construct(
        public readonly string $name,
    ) {}
}

#[\Attribute(\Attribute::TARGET_CLASS)]
final class CommandDescription {
    public function __construct(
        public readonly string $description,
    ) {}
}

#[\Attribute(\Attribute::TARGET_CLASS)]
final class CommandUsage {
    public function __construct(
        public readonly string $usage,
    ) {}
}

#[\Attribute(\Attribute::TARGET_CLASS)]
final class CommandAliases {
    public function __construct(
        public readonly array $aliases,
    ) {}
}

#[\Attribute(\Attribute::TARGET_CLASS)]
final class CommandPermission {
    public function __construct(
        public readonly string $permission,
    ) {}
}

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class CommandArgument {
    public function __construct(
        public readonly string $name,
        public readonly string $type = "string",
        public readonly bool $optional = false,
        public readonly string $description = "",
    ) {}
}

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class CommandRemaining {
    public function __construct(
        public readonly string $name,
        public readonly string $description = "",
    ) {}
}
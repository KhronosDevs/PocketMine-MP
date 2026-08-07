<?php

declare(strict_types=1);

namespace pocketmine\api\plugin;

class PluginDescription {
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $author,
        public readonly array $depend = [],
        public readonly array $softDepend = [],
        public readonly array $commands = [],
        public readonly array $permissions = [],
    ) {}

    public function getName(): string {
        return $this->name;
    }

    public function getVersion(): string {
        return $this->version;
    }

    public function getAuthor(): string {
        return $this->author;
    }

    public function getDepend(): array {
        return $this->depend;
    }

    public function getSoftDepend(): array {
        return $this->softDepend;
    }

    public function getCommands(): array {
        return $this->commands;
    }

    public function getPermissions(): array {
        return $this->permissions;
    }

    public function getFullName(): string {
        return "{$this->name} v{$this->version} by {$this->author}";
    }
}
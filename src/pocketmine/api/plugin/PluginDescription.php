<?php

declare(strict_types=1);

namespace pocketmine\api\plugin;

/**
 * Descriptor parsed from a plugin's plugin.yml. `main` is the FQCN of the
 * plugin class (must extend Plugin) and `api` the minimum API version the
 * plugin requires (loaded only if it fits the server's API_VERSION).
 */
class PluginDescription {
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $author,
        public readonly string $main,
        public readonly string $description = '',
        public readonly string $api = '0.0.0',
        public readonly array $depend = [],
        public readonly array $softDepend = [],
        public readonly array $commands = [],
        public readonly array $permissions = [],
    ) {}

    /**
     * Parse a plugin.yml structure (as returned by yaml_parse). Throws if the
     * mandatory "name" or "main" fields are missing.
     */
    public static function fromArray(array $data): self {
        $name = trim((string)($data['name'] ?? ''));
        $main = trim((string)($data['main'] ?? ''));
        if ($name === '' || $main === '') {
            throw new \InvalidArgumentException('plugin.yml must declare both "name" and "main"');
        }

        return new self(
            name: $name,
            version: (string)($data['version'] ?? '1.0.0'),
            author: (string)($data['author'] ?? 'Unknown'),
            main: $main,
            description: (string)($data['description'] ?? ''),
            api: (string)($data['api'] ?? '0.0.0'),
            depend: (array)($data['depend'] ?? []),
            softDepend: (array)($data['softdepend'] ?? $data['softDepend'] ?? []),
            commands: (array)($data['commands'] ?? []),
            permissions: (array)($data['permissions'] ?? []),
        );
    }

    public function getName(): string {
        return $this->name;
    }

    public function getVersion(): string {
        return $this->version;
    }

    public function getAuthor(): string {
        return $this->author;
    }

    public function getMain(): string {
        return $this->main;
    }

    public function getDescription(): string {
        return $this->description;
    }

    public function getApi(): string {
        return $this->api;
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

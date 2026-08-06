<?php

declare(strict_types=1);

namespace pocketmine\domain\component;

use pocketmine\domain\ecs\Component;

#[Component]
final class MetadataComponent {
    public function __construct(
        public array $data = [],
    ) {}

    public function get(string $key, mixed $default = null): mixed {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void {
        unset($this->data[$key]);
    }

    public function has(string $key): bool {
        return isset($this->data[$key]);
    }
}
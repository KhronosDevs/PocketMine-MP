<?php

declare(strict_types=1);

namespace pocketmine\domain\ecs;

final class ResourceRegistry {
    private array $resources = [];

    public function set(mixed $resource): void {
        $type = is_object($resource) ? get_class($resource) : gettype($resource);
        $this->resources[$type] = $resource;
    }

    public function get(string $type): mixed {
        return $this->resources[$type] ?? null;
    }

    public function has(string $type): bool {
        return isset($this->resources[$type]);
    }

    public function remove(string $type): void {
        unset($this->resources[$type]);
    }
}
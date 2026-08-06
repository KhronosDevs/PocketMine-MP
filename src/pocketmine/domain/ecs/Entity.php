<?php

declare(strict_types=1);

namespace pocketmine\domain\ecs;

final class Entity {
    private static int $nextId = 1;

    public function __construct(
        public readonly int $id,
        private array $components = [],
    ) {}

    public static function generateId(): int {
        return self::$nextId++;
    }

    public function has(string $componentType): bool {
        return isset($this->components[$componentType]);
    }

    public function get(string $componentType): mixed {
        return $this->components[$componentType] ?? null;
    }

    public function set(string $componentType, mixed $component): void {
        $this->components[$componentType] = $component;
    }

    public function remove(string $componentType): void {
        unset($this->components[$componentType]);
    }

    public function getComponents(): array {
        return $this->components;
    }

    public function withComponent(string $type, mixed $component): self {
        $clone = clone $this;
        $clone->components[$type] = $component;
        return $clone;
    }

    public function withoutComponent(string $type): self {
        $clone = clone $this;
        unset($clone->components[$type]);
        return $clone;
    }
}
<?php

declare(strict_types=1);

namespace pocketmine\domain\ecs;

final class Entity {
    private static int $nextId = 1;
    /** @var array<int, Entity> */
    private static array $pool = [];
    private static int $poolSize = 0;
    private const MAX_POOL_SIZE = 10000;

    public function __construct(
        public readonly int $id,
        private array $components = [],
    ) {}

    public static function generateId(): int {
        return self::$nextId++;
    }

    public static function acquire(array $components = []): self {
        if (!empty(self::$pool)) {
            $entity = array_pop(self::$pool);
            self::$poolSize--;
            // Reset entity state
            $reflection = new \ReflectionClass($entity);
            $idProperty = $reflection->getProperty('id');
            $idProperty->setAccessible(true);
            $idProperty->setValue($entity, self::generateId());
            $entity->components = $components;
            return $entity;
        }
        return new self(self::generateId(), $components);
    }

    public static function release(Entity $entity): void {
        if (self::$poolSize < self::MAX_POOL_SIZE) {
            // Clear components
            $entity->components = [];
            self::$pool[] = $entity;
            self::$poolSize++;
        }
    }

    public static function clearPool(): void {
        self::$pool = [];
        self::$poolSize = 0;
    }

    public static function getPoolStats(): array {
        return [
            'size' => self::$poolSize,
            'max' => self::MAX_POOL_SIZE,
        ];
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
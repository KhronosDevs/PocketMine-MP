<?php

declare(strict_types=1);

namespace pocketmine\core\component;

use pocketmine\core\ecs\Component;

#[Component]
final class EffectComponent {
    /** @var array<int, EffectInstance> */
    public array $effects = [];

    public function __construct() {}

    public function add(int $effectId, int $amplifier, int $duration, bool $ambient = false, bool $particles = true): void {
        $this->effects[$effectId] = new EffectInstance($effectId, $amplifier, $duration, $ambient, $particles);
    }

    public function remove(int $effectId): void {
        unset($this->effects[$effectId]);
    }

    public function get(int $effectId): ?EffectInstance {
        return $this->effects[$effectId] ?? null;
    }

    public function has(int $effectId): bool {
        return isset($this->effects[$effectId]);
    }

    public function tick(int $tickDiff = 1): void {
        foreach ($this->effects as $id => $instance) {
            $instance->duration -= $tickDiff;
            if ($instance->duration <= 0) {
                unset($this->effects[$id]);
            }
        }
    }

    public function clear(): void {
        $this->effects = [];
    }

    /** @return array<int, EffectInstance> */
    public function getAll(): array {
        return $this->effects;
    }
}

final class EffectInstance {
    public function __construct(
        public int $effectId,
        public int $amplifier,
        public int $duration,
        public bool $ambient = false,
        public bool $particles = true,
    ) {}

    public function canTick(): bool {
        return $this->duration > 0;
    }

    public function toArray(): array {
        return [
            'id' => $this->effectId,
            'amplifier' => $this->amplifier,
            'duration' => $this->duration,
            'ambient' => $this->ambient,
            'particles' => $this->particles,
        ];
    }

    public static function fromArray(array $data): self {
        return new self(
            $data['id'],
            $data['amplifier'],
            $data['duration'],
            $data['ambient'] ?? false,
            $data['particles'] ?? true,
        );
    }
}
<?php

declare(strict_types=1);

namespace pocketmine\core\component;

use pocketmine\core\ecs\Component;

#[Component]
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

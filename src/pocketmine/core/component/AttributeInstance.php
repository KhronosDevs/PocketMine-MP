<?php

declare(strict_types=1);

namespace pocketmine\core\component;

use pocketmine\core\ecs\Component;

#[Component]
final class AttributeInstance {
    /** @var array<string, AttributeModifier> */
    public array $modifiers = [];
    public float $value = 0.0;
    public float $baseValue = 0.0;

    public function __construct(float $baseValue = 0.0) {
        $this->baseValue = $baseValue;
        $this->value = $baseValue;
    }

    public function toArray(): array {
        return [
            'base' => $this->baseValue,
            'value' => $this->value,
            'modifiers' => array_map(fn(AttributeModifier $m) => $m->toArray(), $this->modifiers),
        ];
    }
}

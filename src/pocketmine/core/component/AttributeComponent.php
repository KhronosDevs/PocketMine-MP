<?php

declare(strict_types=1);

namespace pocketmine\core\component;

use pocketmine\core\ecs\Component;

#[Component]
final class AttributeComponent {
    /** @var array<string, AttributeInstance> */
    public array $attributes = [];

    public function __construct() {}

    public function set(string $name, float $value): void {
        $this->attributes[$name] = new AttributeInstance($value);
    }

    public function get(string $name): float {
        return $this->attributes[$name]?->value ?? 0.0;
    }

    public function getBase(string $name): float {
        return $this->attributes[$name]?->baseValue ?? 0.0;
    }

    public function addModifier(string $attribute, string $modifierId, float $amount, int $operation): void {
        if (!isset($this->attributes[$attribute])) {
            $this->attributes[$attribute] = new AttributeInstance(0.0);
        }
        $this->attributes[$attribute]->modifiers[$modifierId] = new AttributeModifier($modifierId, $amount, $operation);
        $this->recalculate($attribute);
    }

    public function removeModifier(string $attribute, string $modifierId): void {
        if (isset($this->attributes[$attribute])) {
            unset($this->attributes[$attribute]->modifiers[$modifierId]);
            $this->recalculate($attribute);
        }
    }

    private function recalculate(string $attribute): void {
        $attr = $this->attributes[$attribute];
        $value = $attr->baseValue;
        
        // Operation 0: Add, 1: Multiply base, 2: Multiply total
        $add = 0.0;
        $multiplyBase = 1.0;
        $multiplyTotal = 1.0;
        
        foreach ($attr->modifiers as $modifier) {
            switch ($modifier->operation) {
                case 0: $add += $modifier->amount; break;
                case 1: $multiplyBase += $modifier->amount; break;
                case 2: $multiplyTotal *= (1.0 + $modifier->amount); break;
            }
        }
        
        $attr->value = ($value * $multiplyBase + $add) * $multiplyTotal;
    }

    public function toArray(): array {
        $result = [];
        foreach ($this->attributes as $name => $attr) {
            $result[$name] = $attr->toArray();
        }
        return $result;
    }
}

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

final class AttributeModifier {
    public function __construct(
        public string $id,
        public float $amount,
        public int $operation, // 0=add, 1=multiply_base, 2=multiply_total
    ) {}

    public function toArray(): array {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'operation' => $this->operation,
        ];
    }
}
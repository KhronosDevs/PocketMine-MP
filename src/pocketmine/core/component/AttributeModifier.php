<?php

declare(strict_types=1);

namespace pocketmine\core\component;

use pocketmine\core\ecs\Component;

#[Component]
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

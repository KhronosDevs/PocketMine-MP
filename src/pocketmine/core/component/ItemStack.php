<?php

declare(strict_types=1);

namespace pocketmine\core\component;

use pocketmine\core\ecs\Component;

#[Component]
final class ItemStack {
    public function __construct(
        public int $itemId,
        public int $meta = 0,
        public int $count = 1,
        public ?array $nbt = null,
    ) {}

    public function canStackWith(ItemStack $other): bool {
        return $this->itemId === $other->itemId &&
               $this->meta === $other->meta &&
               $this->nbt === $other->nbt;
    }

    private static ?\pocketmine\core\resource\ItemRegistry $itemRegistry = null;

    public function getMaxStackSize(): int {
        self::$itemRegistry ??= \pocketmine\Kernel::getInstance()?->getResourceRegistry()
            ->get(\pocketmine\core\resource\ItemRegistry::class);
        return self::$itemRegistry instanceof \pocketmine\core\resource\ItemRegistry
            ? self::$itemRegistry->getMaxStackSize($this->itemId)
            : 64;
    }

    public function toArray(): array {
        return [
            'id' => $this->itemId,
            'meta' => $this->meta,
            'count' => $this->count,
            'nbt' => $this->nbt,
        ];
    }

    public static function fromArray(array $data): self {
        return new self(
            $data['id'],
            $data['meta'] ?? 0,
            $data['count'] ?? 1,
            $data['nbt'] ?? null,
        );
    }
}

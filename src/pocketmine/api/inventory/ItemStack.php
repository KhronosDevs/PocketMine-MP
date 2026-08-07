<?php

declare(strict_types=1);

namespace pocketmine\api\inventory;

class ItemStack {
    public function __construct(
        public int $itemId = 0,
        public int $meta = 0,
        public int $count = 1,
        public ?array $nbt = null,
    ) {}

    public function getId(): int {
        return $this->itemId;
    }

    public function setId(int $id): void {
        $this->itemId = $id;
    }

    public function getMeta(): int {
        return $this->meta;
    }

    public function setMeta(int $meta): void {
        $this->meta = $meta;
    }

    public function getCount(): int {
        return $this->count;
    }

    public function setCount(int $count): void {
        $this->count = max(0, $count);
    }

    public function getMaxStackSize(): int {
        // Would look up from item registry
        return 64;
    }

    public function isNull(): bool {
        return $this->itemId === 0 || $this->count <= 0;
    }

    public function isSimilar(ItemStack $other): bool {
        return $this->itemId === $other->itemId &&
               $this->meta === $other->meta &&
               $this->nbt === $other->nbt;
    }

    public function canStackWith(ItemStack $other): bool {
        if ($this->isNull() || $other->isNull()) {
            return false;
        }
        return $this->isSimilar($other) && 
               ($this->count + $other->count) <= $this->getMaxStackSize();
    }

    public function getNbt(): ?array {
        return $this->nbt;
    }

    public function setNbt(?array $nbt): void {
        $this->nbt = $nbt;
    }

    public function hasNbt(): bool {
        return $this->nbt !== null && !empty($this->nbt);
    }

    public function getName(): string {
        // Would look up from item registry
        return "item.{$this->itemId}";
    }

    public function getDisplayName(): string {
        return $this->getName();
    }

    public function setCustomName(string $name): void {
        if ($this->nbt === null) {
            $this->nbt = [];
        }
        $this->nbt['display'] = ['Name' => $name];
    }

    public function getLore(): array {
        return $this->nbt['display']['Lore'] ?? [];
    }

    public function setLore(array $lore): void {
        if ($this->nbt === null) {
            $this->nbt = [];
        }
        $this->nbt['display']['Lore'] = $lore;
    }

    public function addEnchantment(int $enchantmentId, int $level): void {
        if ($this->nbt === null) {
            $this->nbt = [];
        }
        $this->nbt['ench'][] = ['id' => $enchantmentId, 'lvl' => $level];
    }

    public function getEnchantments(): array {
        return $this->nbt['ench'] ?? [];
    }

    public function removeEnchantment(int $enchantmentId): void {
        if (isset($this->nbt['ench'])) {
            $this->nbt['ench'] = array_filter(
                $this->nbt['ench'],
                fn($ench) => $ench['id'] !== $enchantmentId
            );
        }
    }

    public function getDamage(): int {
        return $this->nbt['Damage'] ?? 0;
    }

    public function setDamage(int $damage): void {
        if ($this->nbt === null) {
            $this->nbt = [];
        }
        $this->nbt['Damage'] = $damage;
    }

    public function getMaxDurability(): int {
        // Would look up from item registry
        return 0;
    }

    public function isEnchanted(): bool {
        return isset($this->nbt['ench']) && !empty($this->nbt['ench']);
    }

    public function clone(): self {
        return new self(
            $this->itemId,
            $this->meta,
            $this->count,
            $this->nbt ? array_merge([], $this->nbt) : null
        );
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
            $data['nbt'] ?? null
        );
    }
}
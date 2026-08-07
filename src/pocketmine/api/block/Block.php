<?php

declare(strict_types=1);

namespace pocketmine\api\block;

use pocketmine\api\world\World;
use pocketmine\api\inventory\ItemStack;
use pocketmine\core\resource\BlockRegistry;

class Block {
    private World $world;
    private int $x;
    private int $y;
    private int $z;

    public function __construct(World $world, int $x, int $y, int $z) {
        $this->world = $world;
        $this->x = $x;
        $this->y = $y;
        $this->z = $z;
    }

    public function getWorld(): World {
        return $this->world;
    }

    public function getX(): int {
        return $this->x;
    }

    public function getY(): int {
        return $this->y;
    }

    public function getZ(): int {
        return $this->z;
    }

    public function getPosition(): array {
        return ['x' => $this->x, 'y' => $this->y, 'z' => $this->z];
    }

    public function getId(): int {
        return $this->world->getBlock($this->x, $this->y, $this->z);
    }

    public function setId(int $id): bool {
        return $this->world->setBlock($this->x, $this->y, $this->z, $id, $this->getMeta());
    }

    public function getMeta(): int {
        return $this->world->getBlockMeta($this->x, $this->y, $this->z);
    }

    public function setMeta(int $meta): bool {
        return $this->world->setBlock($this->x, $this->y, $this->z, $this->getId(), $meta);
    }

    public function getType(): string {
        return $this->getRegistry()->getName($this->getId());
    }

    public function getName(): string {
        return $this->getType();
    }

    public function isSolid(): bool {
        return $this->getRegistry()->isSolid($this->getId());
    }

    public function isTransparent(): bool {
        return $this->getRegistry()->isTransparent($this->getId());
    }

    public function isPassable(): bool {
        return !$this->isSolid();
    }

    public function isFlammable(): bool {
        return $this->getRegistry()->isFlammable($this->getId());
    }

    public function getFlammability(): int {
        return $this->getRegistry()->getFlammability($this->getId());
    }

    public function getBurnTime(): int {
        return $this->getRegistry()->getBurnTime($this->getId());
    }

    public function getHardness(): float {
        return $this->getRegistry()->getHardness($this->getId());
    }

    public function getResistance(): float {
        return $this->getRegistry()->getResistance($this->getId());
    }

    public function getLightLevel(): int {
        return $this->getRegistry()->getLightLevel($this->getId());
    }

    public function getLightOpacity(): int {
        return $this->getRegistry()->getLightOpacity($this->getId());
    }

    public function isLightSource(): bool {
        return $this->getLightLevel() > 0;
    }

    public function canBeReplaced(): bool {
        return $this->getRegistry()->isReplaceable($this->getId());
    }

    public function canBeSilkTouched(): bool {
        return $this->getRegistry()->canBeSilkTouched($this->getId());
    }

    public function getToolType(): string {
        return $this->getRegistry()->getToolType($this->getId());
    }

    public function getToolLevel(): int {
        return $this->getRegistry()->getToolLevel($this->getId());
    }

    /**
     * @return array<int, ItemStack> item drops for this block.
     */
    public function getDrops(ItemStack $item): array {
        $silkTouch = $item->getId() === 359; // shears
        $drops = $this->getRegistry()->getDrops($this->getId(), $silkTouch);
        $stacks = [];
        foreach ($drops as $drop) {
            $stacks[] = new ItemStack($drop['id'], $drop['meta'], $drop['count']);
        }
        return $stacks;
    }

    public function getExperienceDrop(): int {
        return $this->getRegistry()->getExperienceDrop($this->getId());
    }

    public function isReplaceable(): bool {
        return $this->getRegistry()->isReplaceable($this->getId());
    }

    /**
     * Return the block one block in the given direction.
     *
     * @param array<int, int> $direction [dx, dy, dz]
     */
    public function getBlockFace(array $direction): self {
        return $this->getRelative((int)($direction[0] ?? 0), (int)($direction[1] ?? 0), (int)($direction[2] ?? 0));
    }

    public function getRelative(int $dx, int $dy, int $dz): self {
        return new self($this->world, $this->x + $dx, $this->y + $dy, $this->z + $dz);
    }

    public function distance($block): float {
        if (!$block instanceof Block) return 0;
        $dx = $this->x - $block->getX();
        $dy = $this->y - $block->getY();
        $dz = $this->z - $block->getZ();
        return sqrt($dx * $dx + $dy * $dy + $dz * $dz);
    }

    /**
     * Face index of $to relative to $from (0=down, 1=up, 2=north, 3=south, 4=west, 5=east).
     */
    public function getBlockFaceFromTo(Block $from, Block $to): int {
        $dx = $to->getX() - $from->getX();
        $dy = $to->getY() - $from->getY();
        $dz = $to->getZ() - $from->getZ();
        if (abs($dy) >= abs($dx) && abs($dy) >= abs($dz)) {
            return $dy > 0 ? 1 : 0;
        }
        if (abs($dx) >= abs($dz)) {
            return $dx > 0 ? 5 : 4;
        }
        return $dz > 0 ? 3 : 2;
    }

    private function getRegistry(): BlockRegistry {
        $registry = $this->world->getEcsWorld()->getResourceRegistry()->get(BlockRegistry::class);
        return $registry instanceof BlockRegistry ? $registry : new BlockRegistry();
    }
}

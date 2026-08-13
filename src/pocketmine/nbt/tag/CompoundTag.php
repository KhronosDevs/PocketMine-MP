<?php

declare(strict_types=1);

namespace pocketmine\nbt\tag;

use pocketmine\nbt\NBT;
use RuntimeException;
use function get_class;

/**
 * A named container of child tags, addressed by name. Iterating a
 * CompoundTag yields only its Tag children (dynamic properties hold tags,
 * so foreach over the object naturally skips non-tags).
 */
class CompoundTag extends NamedTag implements \ArrayAccess, \Countable, \IteratorAggregate {
    /**
     * @param NamedTag[] $value
     */
    public function __construct(string $name = "", array $value = []) {
        parent::__construct($name);
        foreach ($value as $tag) {
            if ($tag instanceof NamedTag) {
                $this->{$tag->getName()} = $tag;
            }
        }
    }

    public function getCount(): int {
        $count = 0;
        foreach ((array)$this as $tag) {
            if ($tag instanceof Tag) {
                ++$count;
            }
        }
        return $count;
    }

    public function count(): int {
        return $this->getCount();
    }

    /**
     * @return Tag|null
     */
    public function getTag(string $name) {
        return $this->{$name} ?? null;
    }

    public function getListTag(string $name): ?ListTag {
        $tag = $this->getTag($name);
        if ($tag !== null && !($tag instanceof ListTag)) {
            throw new RuntimeException("Expected a tag of type " . ListTag::class . ", got " . get_class($tag));
        }
        return $tag;
    }

    public function getCompoundTag(string $name): ?CompoundTag {
        $tag = $this->getTag($name);
        if ($tag !== null && !($tag instanceof CompoundTag)) {
            throw new RuntimeException("Expected a tag of type " . CompoundTag::class . ", got " . get_class($tag));
        }
        return $tag;
    }

    public function setTag(string $name, Tag $tag): self {
        if ($tag instanceof NamedTag) {
            $tag->setName($name);
        }
        $this->{$name} = $tag;
        return $this;
    }

    public function removeTag(string ...$names): void {
        foreach ($names as $name) {
            unset($this->{$name});
        }
    }

    private function getTagValue(string $name, string $expectedClass, $default = null) {
        $tag = $this->getTag($name);
        if ($tag instanceof $expectedClass) {
            return $tag->getValue();
        }
        if ($tag !== null) {
            throw new RuntimeException("Expected a tag of type $expectedClass, got " . get_class($tag));
        }
        if ($default === null) {
            throw new RuntimeException("Tag \"$name\" does not exist");
        }
        return $default;
    }

    public function getByte(string $name, int $default = null): int {
        return $this->getTagValue($name, ByteTag::class, $default);
    }

    public function getShort(string $name, int $default = null): int {
        return $this->getTagValue($name, ShortTag::class, $default);
    }

    public function getInt(string $name, int $default = null): int {
        return $this->getTagValue($name, IntTag::class, $default);
    }

    public function getLong(string $name, int $default = null): int {
        return $this->getTagValue($name, LongTag::class, $default);
    }

    public function getFloat(string $name, float $default = null): float {
        return $this->getTagValue($name, FloatTag::class, $default);
    }

    public function getDouble(string $name, float $default = null): float {
        return $this->getTagValue($name, DoubleTag::class, $default);
    }

    public function getByteArray(string $name, string $default = null): string {
        return $this->getTagValue($name, ByteArrayTag::class, $default);
    }

    public function getString(string $name, string $default = null): string {
        return $this->getTagValue($name, StringTag::class, $default);
    }

    /**
     * @param int[]|null $default
     * @return int[]
     */
    public function getIntArray(string $name, array $default = null): array {
        return $this->getTagValue($name, IntArrayTag::class, $default);
    }

    public function setByte(string $name, int $value): self {
        return $this->setTag($name, new ByteTag($name, $value));
    }

    public function setShort(string $name, int $value): self {
        return $this->setTag($name, new ShortTag($name, $value));
    }

    public function setInt(string $name, int $value): self {
        return $this->setTag($name, new IntTag($name, $value));
    }

    public function setLong(string $name, int $value): self {
        return $this->setTag($name, new LongTag($name, $value));
    }

    public function setFloat(string $name, float $value): self {
        return $this->setTag($name, new FloatTag($name, $value));
    }

    public function setDouble(string $name, float $value): self {
        return $this->setTag($name, new DoubleTag($name, $value));
    }

    public function setByteArray(string $name, string $value): self {
        return $this->setTag($name, new ByteArrayTag($name, $value));
    }

    public function setString(string $name, string $value): self {
        return $this->setTag($name, new StringTag($name, $value));
    }

    /**
     * @param int[] $value
     */
    public function setIntArray(string $name, array $value): self {
        return $this->setTag($name, new IntArrayTag($name, $value));
    }

    public function offsetExists(mixed $offset): bool {
        return isset($this->{$offset}) && $this->{$offset} instanceof Tag;
    }

    public function offsetGet(mixed $offset): mixed {
        if (isset($this->{$offset}) && $this->{$offset} instanceof Tag) {
            if ($this->{$offset} instanceof \ArrayAccess) {
                return $this->{$offset};
            }
            return $this->{$offset}->getValue();
        }
        return null;
    }

    public function offsetSet(mixed $offset, mixed $value): void {
        if ($value instanceof Tag) {
            if ($value instanceof NamedTag && is_string($offset)) {
                $value->setName($offset);
            }
            $this->{$offset} = $value;
        } elseif (isset($this->{$offset}) && $this->{$offset} instanceof Tag) {
            $this->{$offset}->setValue($value);
        }
    }

    public function offsetUnset(mixed $offset): void {
        unset($this->{$offset});
    }

    public function getIterator(): \Traversable {
        foreach ((array)$this as $tag) {
            if ($tag instanceof Tag) {
                yield $tag->getName() => $tag;
            }
        }
    }

    public function getType(): int {
        return NBT::TAG_Compound;
    }

    public function read(NBT $nbt): void {
        $this->value = [];
        do {
            $tag = $nbt->readTag();
            if ($tag instanceof NamedTag && $tag->getName() !== "") {
                $this->{$tag->getName()} = $tag;
            }
        } while (!($tag instanceof EndTag) && !$nbt->feof());
    }

    public function write(NBT $nbt): void {
        foreach ((array)$this as $tag) {
            if ($tag instanceof Tag && !($tag instanceof EndTag)) {
                $nbt->writeTag($tag);
            }
        }
        $nbt->writeTag(new EndTag());
    }

    public function __toString(): string {
        $str = get_class($this) . "{\n";
        foreach ((array)$this as $tag) {
            if ($tag instanceof Tag) {
                $str .= get_class($tag) . ":" . $tag->__toString() . "\n";
            }
        }
        return $str . "}";
    }
}

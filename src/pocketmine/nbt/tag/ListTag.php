<?php

declare(strict_types=1);

namespace pocketmine\nbt\tag;

use pocketmine\nbt\NBT;
use function count;
use function get_class;

/**
 * An ordered list of unnamed tags of one type. Children are held in an
 * internal array, so iterating a ListTag never recurses through its own
 * getIterator() (the class is IteratorAggregate).
 */
class ListTag extends NamedTag implements \ArrayAccess, \Countable, \IteratorAggregate {
    private ?int $tagType = null;

    /** @var list<Tag> */
    private array $children = [];

    /**
     * @param Tag[] $value
     */
    public function __construct(string $name = "", array $value = []) {
        parent::__construct($name);
        foreach ($value as $v) {
            if ($v instanceof Tag) {
                $this->children[] = $v;
            }
        }
    }

    public function &getValue() {
        $value = $this->children;
        return $value;
    }

    public function getCount(): int {
        return count($this->children);
    }

    public function count(): int {
        return count($this->children);
    }

    public function offsetExists(mixed $offset): bool {
        return is_int($offset) && isset($this->children[$offset]);
    }

    public function offsetGet(mixed $offset): mixed {
        if (isset($this->children[$offset])) {
            $tag = $this->children[$offset];
            if ($tag instanceof \ArrayAccess) {
                return $tag;
            }
            return $tag->getValue();
        }
        return null;
    }

    public function offsetSet(mixed $offset, mixed $value): void {
        if ($value instanceof Tag) {
            if ($offset === null) {
                $this->children[] = $value;
            } elseif (is_int($offset)) {
                $this->children[$offset] = $value;
            }
        } elseif (isset($this->children[$offset]) && $this->children[$offset] instanceof Tag) {
            $this->children[$offset]->setValue($value);
        }
    }

    public function offsetUnset(mixed $offset): void {
        unset($this->children[$offset]);
        $this->children = array_values($this->children);
    }

    public function getIterator(): \Traversable {
        foreach ($this->children as $i => $tag) {
            yield $i => $tag;
        }
    }

    public function getType(): int {
        return NBT::TAG_List;
    }

    public function setTagType(?int $type): void {
        $this->tagType = $type;
    }

    public function getTagType(): ?int {
        return $this->tagType;
    }

    public function read(NBT $nbt): void {
        $this->children = [];
        $this->tagType = $nbt->getByte();
        $size = $nbt->getInt();
        for ($i = 0; $i < $size && !$nbt->feof(); ++$i) {
            $tag = $nbt->readValueTag($this->tagType);
            if ($tag !== null) {
                $this->children[] = $tag;
            }
        }
    }

    public function write(NBT $nbt): void {
        if ($this->tagType === null) {
            $id = null;
            foreach ($this->children as $tag) {
                if ($tag instanceof Tag) {
                    if ($id === null) {
                        $id = $tag->getType();
                    } elseif ($id !== $tag->getType()) {
                        // mixed-type lists cannot be written
                        $this->tagType = NBT::TAG_End;
                        break;
                    }
                }
            }
            $this->tagType = $id ?? NBT::TAG_End;
        }
        if ($this->tagType === NBT::TAG_End) {
            $nbt->putByte(NBT::TAG_End);
            $nbt->putInt(0);
            return;
        }

        $nbt->putByte($this->tagType);
        $nbt->putInt(count($this->children));
        foreach ($this->children as $tag) {
            $tag->write($nbt);
        }
    }

    public function __toString(): string {
        $str = get_class($this) . "{\n";
        foreach ($this->children as $tag) {
            if ($tag instanceof Tag) {
                $str .= get_class($tag) . ":" . $tag->__toString() . "\n";
            }
        }
        return $str . "}";
    }
}

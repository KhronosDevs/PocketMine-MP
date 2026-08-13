<?php

declare(strict_types=1);

namespace pocketmine\nbt\tag;

use pocketmine\nbt\NBT;

/**
 * Base class for all NBT tags.
 */
abstract class Tag extends \stdClass {
    protected $value;

    public function &getValue() {
        return $this->value;
    }

    abstract public function getType(): int;

    public function setValue($value): void {
        $this->value = $value;
    }

    abstract public function write(NBT $nbt): void;

    abstract public function read(NBT $nbt): void;

    public function __toString(): string {
        return (string)$this->value;
    }
}

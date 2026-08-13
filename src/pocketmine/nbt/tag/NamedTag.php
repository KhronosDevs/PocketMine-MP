<?php

declare(strict_types=1);

namespace pocketmine\nbt\tag;

/**
 * A tag that carries a name (every tag inside a CompoundTag).
 */
abstract class NamedTag extends Tag {
    protected string $__name;

    public function __construct(string $name = "", $value = null) {
        $this->__name = $name;
        if ($value !== null) {
            $this->value = $value;
        }
    }

    public function getName(): string {
        return $this->__name;
    }

    public function setName(string $name): void {
        $this->__name = $name;
    }
}

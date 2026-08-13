<?php

declare(strict_types=1);

namespace pocketmine\nbt\tag;

use pocketmine\nbt\NBT;

class FloatTag extends NamedTag {
    public function getType(): int {
        return NBT::TAG_Float;
    }

    public function read(NBT $nbt): void {
        $this->value = $nbt->getFloat();
    }

    public function write(NBT $nbt): void {
        $nbt->putFloat((float)$this->value);
    }
}

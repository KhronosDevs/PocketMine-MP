<?php

declare(strict_types=1);

namespace pocketmine\nbt\tag;

use pocketmine\nbt\NBT;

class DoubleTag extends NamedTag {
    public function getType(): int {
        return NBT::TAG_Double;
    }

    public function read(NBT $nbt): void {
        $this->value = $nbt->getDouble();
    }

    public function write(NBT $nbt): void {
        $nbt->putDouble((float)$this->value);
    }
}

<?php

declare(strict_types=1);

namespace pocketmine\nbt\tag;

use pocketmine\nbt\NBT;

class ByteTag extends NamedTag {
    public function getType(): int {
        return NBT::TAG_Byte;
    }

    public function read(NBT $nbt): void {
        $this->value = $nbt->getByte();
    }

    public function write(NBT $nbt): void {
        $nbt->putByte((int)$this->value);
    }
}

<?php

declare(strict_types=1);

namespace pocketmine\nbt\tag;

use pocketmine\nbt\NBT;

class ShortTag extends NamedTag {
    public function getType(): int {
        return NBT::TAG_Short;
    }

    public function read(NBT $nbt): void {
        $this->value = $nbt->getShort();
    }

    public function write(NBT $nbt): void {
        $nbt->putShort((int)$this->value);
    }
}

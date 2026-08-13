<?php

declare(strict_types=1);

namespace pocketmine\nbt\tag;

use pocketmine\nbt\NBT;
use function strlen;

class StringTag extends NamedTag {
    public function getType(): int {
        return NBT::TAG_String;
    }

    public function read(NBT $nbt): void {
        $this->value = $nbt->getString();
    }

    public function write(NBT $nbt): void {
        $nbt->putString((string)$this->value);
    }
}

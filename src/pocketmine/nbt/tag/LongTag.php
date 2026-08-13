<?php

declare(strict_types=1);

namespace pocketmine\nbt\tag;

use pocketmine\nbt\NBT;

class LongTag extends NamedTag {
    public function getType(): int {
        return NBT::TAG_Long;
    }

    public function read(NBT $nbt): void {
        $this->value = $nbt->getLong();
    }

    public function write(NBT $nbt): void {
        $nbt->putLong((int)$this->value);
    }
}

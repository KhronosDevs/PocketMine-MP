<?php

declare(strict_types=1);

namespace pocketmine\nbt\tag;

use pocketmine\nbt\NBT;

class EndTag extends Tag {
    public function getType(): int {
        return NBT::TAG_End;
    }

    public function read(NBT $nbt): void {
    }

    public function write(NBT $nbt): void {
    }
}

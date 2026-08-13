<?php

declare(strict_types=1);

namespace pocketmine\nbt\tag;

use pocketmine\nbt\NBT;
use function strlen;

class ByteArrayTag extends NamedTag {
    public function getType(): int {
        return NBT::TAG_ByteArray;
    }

    public function read(NBT $nbt): void {
        $this->value = $nbt->get($nbt->getInt());
    }

    public function write(NBT $nbt): void {
        $nbt->putInt(strlen((string)$this->value));
        $nbt->put((string)$this->value);
    }
}

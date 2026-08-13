<?php

declare(strict_types=1);

namespace pocketmine\nbt\tag;

use pocketmine\nbt\NBT;
use function array_values;
use function count;
use function implode;
use function pack;
use function unpack;

class IntArrayTag extends NamedTag {
    public function getType(): int {
        return NBT::TAG_IntArray;
    }

    public function read(NBT $nbt): void {
        $size = $nbt->getInt();
        $this->value = array_values(unpack(
            $nbt->endianness === NBT::LITTLE_ENDIAN ? "V*" : "N*",
            $nbt->get($size * 4)
        ));
    }

    public function write(NBT $nbt): void {
        $nbt->putInt(count($this->value));
        $nbt->put(pack(
            $nbt->endianness === NBT::LITTLE_ENDIAN ? "V*" : "N*",
            ...array_values((array)$this->value)
        ));
    }

    public function __toString(): string {
        return get_class($this) . "{\n" . implode(", ", (array)$this->value) . "}";
    }
}

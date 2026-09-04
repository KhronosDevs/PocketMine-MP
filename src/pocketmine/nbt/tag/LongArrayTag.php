<?php

declare(strict_types=1);

namespace pocketmine\nbt\tag;

use pocketmine\nbt\NBT;
use pocketmine\utils\Binary;
use function count;
use function implode;

/**
 * TAG_Long_Array (12): a list of signed 64-bit big-endian integers.
 *
 * Java Edition chunks (1.13+) store the packed palette "BlockStates" index
 * buffer as a long array, so reading modern Java worlds needs this tag
 * type. Values are stored as PHP signed ints exactly as read off the wire
 * (bit 63 set shows as negative); consumers that need unsigned semantics
 * split the value with shifts and masks.
 */
class LongArrayTag extends NamedTag {
    /**
     * @param int[] $value signed 64-bit longs
     */
    public function __construct(string $name = "", array $value = []) {
        parent::__construct($name);
        $this->value = $value;
    }

    public function getType(): int {
        return NBT::TAG_Long_Array;
    }

    public function read(NBT $nbt): void {
        $size = $nbt->getInt();
        $value = [];
        for ($i = 0; $i < $size; $i++) {
            $value[] = Binary::readLong($nbt->get(8));
        }
        $this->value = $value;
    }

    public function write(NBT $nbt): void {
        $values = (array)$this->value;
        $nbt->putInt(count($values));
        foreach ($values as $long) {
            $nbt->put(Binary::writeLong((int)$long));
        }
    }

    public function __toString(): string {
        return get_class($this) . "{\n" . implode(", ", (array)$this->value) . "}";
    }
}

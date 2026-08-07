<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 */

declare(strict_types=1);

namespace pocketmine\utils;

use pocketmine\item\Item;
use function chr;
use function ord;
use function strlen;
use function substr;

class BinaryStream extends \stdClass
{

    public string $buffer = "";
    public int $offset = 0;

    public function __construct(string $buffer = "", int $offset = 0)
    {
        $this->buffer = $buffer;
        $this->offset = $offset;
    }

    public function reset(): void
    {
        $this->buffer = "";
        $this->offset = 0;
    }

    public function setBuffer(string $buffer, int $offset = 0): void
    {
        $this->buffer = $buffer;
        $this->offset = $offset;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function getBuffer(): string
    {
        return $this->buffer;
    }

    /**
     * @param int|bool $len If true, the rest of the buffer is returned.
     */
    public function get(int|bool $len): string
    {
        if ($len < 0) {
            $this->offset = strlen($this->buffer) - 1;
            return "";
        } elseif ($len === true) {
            return substr($this->buffer, $this->offset);
        }

        return $len === 1
            ? $this->buffer[$this->offset++]
            : substr($this->buffer, ($this->offset += $len) - $len, $len);
    }

    public function put(string $str): void
    {
        $this->buffer .= $str;
    }

    public function getLong(): int
    {
        return Binary::readLong($this->get(8));
    }

    public function putLong(int $v): void
    {
        $this->buffer .= Binary::writeLong($v);
    }

    public function getInt(): int
    {
        return Binary::readInt($this->get(4));
    }

    public function putInt(int $v): void
    {
        $this->buffer .= Binary::writeInt($v);
    }

    public function getLLong(): int
    {
        return Binary::readLLong($this->get(8));
    }

    public function putLLong(int $v): void
    {
        $this->buffer .= Binary::writeLLong($v);
    }

    public function getLInt(): int
    {
        return Binary::readLInt($this->get(4));
    }

    public function putLInt(int $v): void
    {
        $this->buffer .= Binary::writeLInt($v);
    }

    public function getSignedShort(): int
    {
        return Binary::readSignedShort($this->get(2));
    }

    public function putShort(int $v): void
    {
        $this->buffer .= Binary::writeShort($v);
    }

    public function getShort(): int
    {
        return Binary::readShort($this->get(2));
    }

    public function putSignedShort(int $v): void
    {
        $this->buffer .= Binary::writeShort($v);
    }

    public function getFloat(): float
    {
        return Binary::readFloat($this->get(4));
    }

    public function putFloat(float $v): void
    {
        $this->buffer .= Binary::writeFloat($v);
    }

    public function getLShort(bool $signed = true): int
    {
        return $signed
            ? Binary::readSignedLShort($this->get(2))
            : Binary::readLShort($this->get(2));
    }

    public function putLShort(int $v): void
    {
        $this->buffer .= Binary::writeLShort($v);
    }

    public function getLFloat(): float
    {
        return Binary::readLFloat($this->get(4));
    }

    public function putLFloat(float $v): void
    {
        $this->buffer .= Binary::writeLFloat($v);
    }

    public function getTriad(): int
    {
        return Binary::readTriad($this->get(3));
    }

    public function putTriad(int $v): void
    {
        $this->buffer .= Binary::writeTriad($v);
    }

    public function getLTriad(): int
    {
        return Binary::readLTriad($this->get(3));
    }

    public function putLTriad(int $v): void
    {
        $this->buffer .= Binary::writeLTriad($v);
    }

    public function getByte(): int
    {
        return ord($this->buffer[$this->offset++]);
    }

    public function putByte(int $v): void
    {
        $this->buffer .= chr($v);
    }

    public function getDataArray(int $len = 10): array
    {
        $data = [];

        for ($i = 1; $i <= $len && !$this->feof(); ++$i) {
            $data[] = $this->get($this->getTriad());
        }

        return $data;
    }

    public function putDataArray(array $data = []): void
    {
        foreach ($data as $v) {
            $this->putTriad(strlen($v));
            $this->put($v);
        }
    }

    public function getUUID(): UUID
    {
        return UUID::fromBinary($this->get(16));
    }

    public function putUUID(UUID $uuid): void
    {
        $this->put($uuid->toBinary());
    }

    public function getSlot(): Item
    {
        $id = $this->getSignedShort();

        if ($id <= 0) {
            return Item::get(0, 0, 0);
        }

        $cnt = $this->getByte();
        $data = $this->getShort();

        $nbtLen = $this->getLShort();

        $nbt = "";

        if ($nbtLen > 0) {
            $nbt = $this->get($nbtLen);
        }

        return Item::get(
            $id,
            $data,
            $cnt,
            $nbt
        );
    }

    public function putSlot(Item $item): void
    {
        if ($item->getId() === 0) {
            $this->putShort(0);
            return;
        }

        $this->putShort($item->getId());
        $this->putByte($item->getCount());
        $this->putShort($item->getDamage() === null ? -1 : $item->getDamage());

        $nbt = $item->getCompoundTag();

        $this->putLShort(strlen($nbt));
        $this->put($nbt);
    }

    public function getString(): string
    {
        return $this->get($this->getShort());
    }

    public function putString(?string $v): void
    {
        $this->putShort($v === null ? 0 : strlen($v));
        $this->put((string) $v);
    }

    public function feof(): bool
    {
        return !isset($this->buffer[$this->offset]);
    }
}

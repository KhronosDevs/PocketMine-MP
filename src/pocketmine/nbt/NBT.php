<?php

declare(strict_types=1);

namespace pocketmine\nbt;

use pocketmine\nbt\tag\ByteArrayTag;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\EndTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\IntArrayTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\LongTag;
use pocketmine\nbt\tag\NamedTag;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\Tag;
use pocketmine\utils\Binary;
use function is_array;
use function strlen;
use function substr;
use function zlib_decode;
use function zlib_encode;

/**
 * Named Binary Tag encoder/decoder (big- or little-endian).
 *
 * Ported from the legacy PocketMine NBT codec with the preprocessor
 * directives and Item/JSON helpers stripped: the binary format, tag types
 * and endianness handling are byte-for-byte the same, so files written by
 * real Anvil/McRegion providers (and by this server) interoperate.
 */
class NBT {
    public const LITTLE_ENDIAN = 0;
    public const BIG_ENDIAN = 1;

    public const TAG_End = 0;
    public const TAG_Byte = 1;
    public const TAG_Short = 2;
    public const TAG_Int = 3;
    public const TAG_Long = 4;
    public const TAG_Float = 5;
    public const TAG_Double = 6;
    public const TAG_ByteArray = 7;
    public const TAG_String = 8;
    public const TAG_List = 9;
    public const TAG_Compound = 10;
    public const TAG_IntArray = 11;

    public string $buffer = "";
    private int $offset = 0;
    public int $endianness;
    /** @var CompoundTag|list<Tag>|null */
    private $data;

    public function __construct(int $endianness = self::LITTLE_ENDIAN) {
        $this->offset = 0;
        $this->endianness = $endianness & 0x01;
    }

    public function get(int $len): string {
        if ($len < 0) {
            $this->offset = strlen($this->buffer) - 1;
            return "";
        }
        if ($len === true) {
            return substr($this->buffer, $this->offset);
        }
        return $len === 1 ? $this->buffer[$this->offset++] : substr($this->buffer, ($this->offset += $len) - $len, $len);
    }

    public function put(string $v): void {
        $this->buffer .= $v;
    }

    public function feof(): bool {
        return !isset($this->buffer[$this->offset]);
    }

    /**
     * Read a single root tag from a buffer.
     */
    public function read(string $buffer, bool $doMultiple = false): void {
        $this->offset = 0;
        $this->buffer = $buffer;
        $this->data = $this->readTag();
        if ($doMultiple && $this->offset < strlen($this->buffer)) {
            $this->data = [$this->data];
            do {
                $this->data[] = $this->readTag();
            } while ($this->offset < strlen($this->buffer));
        }
        $this->buffer = "";
    }

    /**
     * Read a gzip-compressed buffer (vanilla level.dat / chunk payloads).
     */
    public function readCompressed(string $buffer): void {
        $decoded = zlib_decode($buffer);
        if ($decoded === false) {
            throw new \RuntimeException("Failed to decompress NBT payload");
        }
        $this->read($decoded);
    }

    public function write(): string {
        $this->offset = 0;
        $this->buffer = "";
        if ($this->data instanceof CompoundTag) {
            $this->writeTag($this->data);
            return $this->buffer;
        }
        if (is_array($this->data)) {
            foreach ($this->data as $tag) {
                if ($tag instanceof Tag) {
                    $this->writeTag($tag);
                }
            }
            return $this->buffer;
        }
        return "";
    }

    /**
     * @param int $level 1-9 zlib level
     */
    public function writeCompressed(int $level = 7): string {
        $write = $this->write();
        $compressed = zlib_encode($write, ZLIB_ENCODING_GZIP, $level);
        if ($compressed === false) {
            throw new \RuntimeException("Failed to compress NBT payload");
        }
        return $compressed;
    }

    public function readTag(): Tag {
        $type = $this->getByte();
        switch ($type) {
            case self::TAG_Byte:
                $tag = new ByteTag($this->getString());
                $tag->read($this);
                break;
            case self::TAG_Short:
                $tag = new ShortTag($this->getString());
                $tag->read($this);
                break;
            case self::TAG_Int:
                $tag = new IntTag($this->getString());
                $tag->read($this);
                break;
            case self::TAG_Long:
                $tag = new LongTag($this->getString());
                $tag->read($this);
                break;
            case self::TAG_Float:
                $tag = new FloatTag($this->getString());
                $tag->read($this);
                break;
            case self::TAG_Double:
                $tag = new DoubleTag($this->getString());
                $tag->read($this);
                break;
            case self::TAG_ByteArray:
                $tag = new ByteArrayTag($this->getString());
                $tag->read($this);
                break;
            case self::TAG_String:
                $tag = new StringTag($this->getString());
                $tag->read($this);
                break;
            case self::TAG_List:
                $tag = new ListTag($this->getString());
                $tag->read($this);
                break;
            case self::TAG_Compound:
                $tag = new CompoundTag($this->getString());
                $tag->read($this);
                break;
            case self::TAG_IntArray:
                $tag = new IntArrayTag($this->getString());
                $tag->read($this);
                break;
            case self::TAG_End: // no named tag
            default:
                $tag = new EndTag();
                break;
        }
        return $tag;
    }

    /**
     * Read one unnamed value tag of a known type (ListTag elements).
     */
    public function readValueTag(int $type): ?Tag {
        switch ($type) {
            case self::TAG_Byte:
                $tag = new ByteTag("");
                $tag->read($this);
                return $tag;
            case self::TAG_Short:
                $tag = new ShortTag("");
                $tag->read($this);
                return $tag;
            case self::TAG_Int:
                $tag = new IntTag("");
                $tag->read($this);
                return $tag;
            case self::TAG_Long:
                $tag = new LongTag("");
                $tag->read($this);
                return $tag;
            case self::TAG_Float:
                $tag = new FloatTag("");
                $tag->read($this);
                return $tag;
            case self::TAG_Double:
                $tag = new DoubleTag("");
                $tag->read($this);
                return $tag;
            case self::TAG_ByteArray:
                $tag = new ByteArrayTag("");
                $tag->read($this);
                return $tag;
            case self::TAG_String:
                $tag = new StringTag("");
                $tag->read($this);
                return $tag;
            case self::TAG_List:
                $tag = new ListTag("");
                $tag->read($this);
                return $tag;
            case self::TAG_Compound:
                $tag = new CompoundTag("");
                $tag->read($this);
                return $tag;
            case self::TAG_IntArray:
                $tag = new IntArrayTag("");
                $tag->read($this);
                return $tag;
            default:
                return null;
        }
    }

    public function writeTag(Tag $tag): void {
        $this->putByte($tag->getType());
        if ($tag instanceof NamedTag) {
            $this->putString($tag->getName());
        }
        $tag->write($this);
    }

    public function getByte(): int {
        return Binary::readByte($this->get(1));
    }

    public function putByte(int $v): void {
        $this->buffer .= Binary::writeByte($v);
    }

    public function getShort(): int {
        return $this->endianness === self::BIG_ENDIAN
            ? Binary::readShort($this->get(2))
            : Binary::readLShort($this->get(2));
    }

    public function putShort(int $v): void {
        $this->buffer .= $this->endianness === self::BIG_ENDIAN
            ? Binary::writeShort($v)
            : Binary::writeLShort($v);
    }

    public function getInt(): int {
        return $this->endianness === self::BIG_ENDIAN
            ? Binary::readInt($this->get(4))
            : Binary::readLInt($this->get(4));
    }

    public function putInt(int $v): void {
        $this->buffer .= $this->endianness === self::BIG_ENDIAN
            ? Binary::writeInt($v)
            : Binary::writeLInt($v);
    }

    public function getLong(): int {
        return $this->endianness === self::BIG_ENDIAN
            ? Binary::readLong($this->get(8))
            : Binary::readLLong($this->get(8));
    }

    public function putLong(int $v): void {
        $this->buffer .= $this->endianness === self::BIG_ENDIAN
            ? Binary::writeLong($v)
            : Binary::writeLLong($v);
    }

    public function getFloat(): float {
        return $this->endianness === self::BIG_ENDIAN
            ? Binary::readFloat($this->get(4))
            : Binary::readLFloat($this->get(4));
    }

    public function putFloat(float $v): void {
        $this->buffer .= $this->endianness === self::BIG_ENDIAN
            ? Binary::writeFloat($v)
            : Binary::writeLFloat($v);
    }

    public function getDouble(): float {
        return $this->endianness === self::BIG_ENDIAN
            ? Binary::readDouble($this->get(8))
            : Binary::readLDouble($this->get(8));
    }

    public function putDouble(float $v): void {
        $this->buffer .= $this->endianness === self::BIG_ENDIAN
            ? Binary::writeDouble($v)
            : Binary::writeLDouble($v);
    }

    public function getString(): string {
        return $this->get($this->getShort());
    }

    public function putString(string $v): void {
        $this->putShort(strlen($v));
        $this->buffer .= $v;
    }

    /**
     * @return CompoundTag|list<Tag>|null
     */
    public function getData() {
        return $this->data;
    }

    /**
     * @param CompoundTag|list<Tag> $data
     */
    public function setData($data): void {
        $this->data = $data;
    }
}

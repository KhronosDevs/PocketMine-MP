<?php

declare(strict_types=1);

namespace pocketmine\protocol;

use function count;

/**
 * Protocol 84 server->client: map item data (0x3b). Delivers the pixel
 * texture and/or decorations for a map item (id 358) so the client renders
 * it when held. Wire format (varint body, identical layout across the
 * protocol-84 -> 113 era, only the packet id shifted):
 *
 *   long   mapId (entity unique id)
 *   uvarint type bitfield: 0x02 texture, 0x04 decorations, 0x08 tracked eids
 *   byte   scale                      (texture|decorations)
 *   uvarint decorationCount + per-decoration:
 *          varint (rot & 0x0f) | (icon << 4), byte offsetX, byte offsetY,
 *          string label, int ARGB color
 *   varint width, height, offsetX, offsetY
 *   width*height uvarint ABGR colors, row-major (texture)
 */
class ClientboundMapItemDataPacket extends DataPacket {
    const NETWORK_ID = Info::CLIENTBOUND_MAP_ITEM_DATA_PACKET;

    const BITFLAG_TEXTURE_UPDATE = 0x02;
    const BITFLAG_DECORATION_UPDATE = 0x04;
    const BITFLAG_ENTITY_UPDATE = 0x08;

    /** @var int */
    public $mapId = 0;
    /** @var list<int> tracked entity ids (player arrow on the map) */
    public $eids = [];
    /** @var int */
    public $scale = 0;
    /**
     * Each: {rot: int, img: int, xOffset: int, yOffset: int, label: string, color: int ARGB}
     * @var list<array{rot: int, img: int, xOffset: int, yOffset: int, label: string, color: int}>
     */
    public $decorations = [];
    /** @var int */
    public $width = 0;
    /** @var int */
    public $height = 0;
    /** @var int */
    public $xOffset = 0;
    /** @var int */
    public $yOffset = 0;
    /**
     * Row-major ABGR ints (alpha << 24 | blue << 16 | green << 8 | red).
     * @var list<int>
     */
    public $colors = [];

    public function decode(): void {
        // server->client only
    }

    public function encode(): void {
        $this->reset();
        // Entity unique id: a plain long on this codebase (AddEntityPacket parity).
        $this->putLong($this->mapId);

        $type = 0;
        $eidsCount = count($this->eids);
        $decorationCount = count($this->decorations);
        if ($eidsCount > 0) {
            $type |= self::BITFLAG_ENTITY_UPDATE;
        }
        if ($decorationCount > 0) {
            $type |= self::BITFLAG_DECORATION_UPDATE;
        }
        if ($this->colors !== []) {
            $type |= self::BITFLAG_TEXTURE_UPDATE;
        }
        $this->putUnsignedVarInt($type);

        if (($type & self::BITFLAG_ENTITY_UPDATE) !== 0) {
            $this->putUnsignedVarInt($eidsCount);
            foreach ($this->eids as $eid) {
                $this->putEntityUniqueId($eid);
            }
        }

        if (($type & (self::BITFLAG_TEXTURE_UPDATE | self::BITFLAG_DECORATION_UPDATE)) !== 0) {
            $this->putByte($this->scale);
        }

        if (($type & self::BITFLAG_DECORATION_UPDATE) !== 0) {
            $this->putUnsignedVarInt($decorationCount);
            foreach ($this->decorations as $decoration) {
                $this->putVarInt(($decoration["rot"] & 0x0f) | ($decoration["img"] << 4));
                $this->putByte($decoration["xOffset"]);
                $this->putByte($decoration["yOffset"]);
                $this->putString($decoration["label"]);
                $this->putLInt($decoration["color"]);
            }
        }

        if (($type & self::BITFLAG_TEXTURE_UPDATE) !== 0) {
            $this->putVarInt($this->width);
            $this->putVarInt($this->height);
            $this->putVarInt($this->xOffset);
            $this->putVarInt($this->yOffset);
            foreach ($this->colors as $color) {
                $this->putUnsignedVarInt($color);
            }
        }
    }
}

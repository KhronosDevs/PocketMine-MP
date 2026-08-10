<?php

declare(strict_types=1);

namespace pocketmine\protocol;

/**
 * Server-to-client armor broadcast (0x1c, legacy MobArmorEquipmentPacket):
 * the equipped helmet/chestplate/leggings/boots of an entity, shown to every
 * viewer (and the actor itself). Sent whenever a player's armor changes.
 */
class MobArmorEquipmentPacket extends DataPacket {
    public const NETWORK_ID = Info::MOB_ARMOR_EQUIPMENT_PACKET;

    public int $eid = 0;

    /** @var list<array{0: int, 1: int, 2: int, 3: mixed}> slot arrays, index 0-3 */
    public array $slots = [
        [0, 0, 0, null],
        [0, 0, 0, null],
        [0, 0, 0, null],
        [0, 0, 0, null],
    ];

    public function decode(): void {
        $this->eid = $this->getLong();
        for ($i = 0; $i < 4; $i++) {
            $this->slots[$i] = $this->getSlot();
        }
    }

    public function encode(): void {
        $this->reset();
        $this->putLong($this->eid);
        for ($i = 0; $i < 4; $i++) {
            $this->putSlot($this->slots[$i]);
        }
    }
}

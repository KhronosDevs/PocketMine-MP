<?php

declare(strict_types=1);

namespace pocketmine\protocol;

use pocketmine\utils\BinaryStream;
use function count;

/**
 * UpdateAttributesPacket (0x1a) - protocol 84.
 *
 * Syncs an entity's attributes (health, hunger, XP level/progress, ...) to
 * the client. Used here for the player's own XP bar: legacy Player::sendAttributes()
 * pushed player.level + player.experience after any XP change.
 *
 * Wire format (legacy 0.15.x):
 *   long  entityId   (0 = the player's own HUD)
 *   short count
 *   per entry: float min, float max, float value, string name
 */
class UpdateAttributesPacket extends DataPacket {
    public const NETWORK_ID = Info::UPDATE_ATTRIBUTES_PACKET;

    public int $entityId = 0;
    /** @var list<array{0: float, 1: float, 2: float, 3: string}> */
    public array $entries = [];

    public function decode(): void {
        $this->entityId = $this->getLong();
        $count = $this->getShort();
        $this->entries = [];
        for ($i = 0; $i < $count; $i++) {
            $min = $this->getFloat();
            $max = $this->getFloat();
            $value = $this->getFloat();
            $name = $this->getString();
            $this->entries[] = [$min, $max, $value, $name];
        }
    }

    public function encode(): void {
        $this->reset();
        $this->putLong($this->entityId);
        $this->putShort(count($this->entries));
        foreach ($this->entries as [$min, $max, $value, $name]) {
            $this->putFloat($min);
            $this->putFloat($max);
            $this->putFloat($value);
            $this->putString($name);
        }
    }
}

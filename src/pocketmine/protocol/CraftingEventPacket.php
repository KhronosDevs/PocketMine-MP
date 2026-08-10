<?php

declare(strict_types=1);

namespace pocketmine\protocol;

use pocketmine\utils\UUID;

/**
 * Protocol 84 client->server: a craft request. The client sends its crafting
 * grid contents (input) and the claimed result (output) after the player
 * clicks the result slot. Legacy 0x30 layout:
 *
 *   windowId (byte), type (int), recipe UUID,
 *   input count (int) + input slots,
 *   output count (int) + output slots
 */
class CraftingEventPacket extends DataPacket {
    const NETWORK_ID = Info::CRAFTING_EVENT_PACKET;

    public int $windowId = 0;
    public int $type = 0;
    public UUID $id;
    /** @var list<array{0: int, 1: int, 2: int, 3: ?string}> */
    public array $input = [];
    /** @var list<array{0: int, 1: int, 2: int, 3: ?string}> */
    public array $output = [];

    public function decode(): void {
        $this->windowId = $this->getByte();
        $this->type = $this->getInt();
        $this->id = $this->getUUID();

        $size = $this->getInt();
        for ($i = 0; $i < $size && $i < 128; $i++) {
            $this->input[] = $this->getSlot();
        }

        $size = $this->getInt();
        for ($i = 0; $i < $size && $i < 128; $i++) {
            $this->output[] = $this->getSlot();
        }
    }

    public function encode(): void {
        // Mirrors decode so the test client can send this packet over the
        // wire; the server only ever decodes it.
        $this->reset();
        $this->putByte($this->windowId);
        $this->putInt($this->type);
        $this->putUUID($this->id);
        $this->putInt(count($this->input));
        foreach ($this->input as $slot) {
            $this->putSlot($slot);
        }
        $this->putInt(count($this->output));
        foreach ($this->output as $slot) {
            $this->putSlot($slot);
        }
    }
}

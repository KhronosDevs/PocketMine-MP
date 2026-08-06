<?php

declare(strict_types=1);

namespace pocketmine\plugin\api\event;

abstract class Event {
    private bool $cancelled = false;

    public function isCancelled(): bool {
        return $this->cancelled;
    }

    public function setCancelled(bool $cancelled): void {
        $this->cancelled = $cancelled;
    }

    public function getEventName(): string {
        return get_class($this);
    }
}

final class CancellableEvent extends Event {
    // Marker for cancellable events
}
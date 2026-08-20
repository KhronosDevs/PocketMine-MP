<?php

declare(strict_types=1);

namespace pocketmine\adapter\driving\plugin;

use pocketmine\port\driving\EventPort;

final class PluginEventAdapter implements EventPort {
    private array $listeners = [];

    public function subscribe(string $eventClass, callable $handler, int $priority = 0): void {
        $this->listeners[$eventClass] ??= [];
        $this->listeners[$eventClass][] = [$priority, $handler];
        // Sort by priority (higher first)
        usort($this->listeners[$eventClass], fn($a, $b) => $b[0] <=> $a[0]);
    }

    public function unsubscribe(string $eventClass, callable $handler): void {
        if (!isset($this->listeners[$eventClass])) {
            return;
        }
        $this->listeners[$eventClass] = array_filter(
            $this->listeners[$eventClass],
            fn($entry) => $entry[1] !== $handler
        );
    }

    public function emit(object $event): void {
        $eventClass = get_class($event);
        $eventParents = class_parents($eventClass);
        $eventParents[] = $eventClass;

        foreach ($eventParents as $parentClass) {
            if (isset($this->listeners[$parentClass])) {
                foreach ($this->listeners[$parentClass] as [$priority, $handler]) {
                    try {
                        $handler($event);
                    } catch (\Throwable $e) {
                        // Log error but continue
                        error_log("Event handler error: " . $e->getMessage());
                    }
                }
            }
        }
    }
}
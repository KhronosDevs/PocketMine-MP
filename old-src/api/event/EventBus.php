<?php

declare(strict_types=1);

namespace pocketmine\api\event;

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

class CancellableEvent extends Event {
    // Marker for cancellable events
}

#[\Attribute(\Attribute::TARGET_CLASS)]
final class EventPriority {
    public const int LOWEST = 0;
    public const int LOW = 1;
    public const int NORMAL = 2;
    public const int HIGH = 3;
    public const int HIGHEST = 4;
    public const int MONITOR = 5;
}

#[\Attribute(\Attribute::TARGET_METHOD)]
final class EventHandler {
    public function __construct(
        public int $priority = EventPriority::NORMAL,
        public bool $ignoreCancelled = false,
    ) {}
}

class EventBus {
    /** @var array<class-string<Event>, array<ListenerEntry>> */
    private array $listeners = [];

    public function subscribe(string $eventClass, callable $handler, int $priority = EventPriority::NORMAL, bool $ignoreCancelled = false): void {
        if (!is_subclass_of($eventClass, Event::class)) {
            throw new \InvalidArgumentException("Event class must extend Event: $eventClass");
        }

        $this->listeners[$eventClass] ??= [];
        $this->listeners[$eventClass][] = new ListenerEntry($handler, $priority, $ignoreCancelled);

        // Sort by priority (highest first)
        usort($this->listeners[$eventClass], fn(ListenerEntry $a, ListenerEntry $b) => $b->priority <=> $a->priority);
    }

    public function unsubscribe(string $eventClass, callable $handler): void {
        if (!isset($this->listeners[$eventClass])) {
            return;
        }

        $this->listeners[$eventClass] = array_filter(
            $this->listeners[$eventClass],
            fn(ListenerEntry $entry) => $entry->handler !== $handler
        );
    }

    public function emit(object $event): void {
        if (!$event instanceof Event) {
            throw new \InvalidArgumentException("Event must extend Event class");
        }

        $eventClass = get_class($event);
        $eventParents = class_parents($eventClass);
        $eventParents[] = $eventClass;

        foreach ($eventParents as $parentClass) {
            if (isset($this->listeners[$parentClass])) {
                foreach ($this->listeners[$parentClass] as $entry) {
                    if ($event->isCancelled() && !$entry->ignoreCancelled) {
                        continue;
                    }

                    try {
                        ($entry->handler)($event);
                    } catch (\Throwable $e) {
                        // Log error but continue
                        error_log("Event handler error for {$parentClass}: " . $e->getMessage());
                    }
                }
            }
        }
    }

    public function emitAsync(object $event): void {
        // For async events, we could use the threading port
        // For now, just emit synchronously
        $this->emit($event);
    }
}

final class ListenerEntry {
    public function __construct(
        public readonly callable $handler,
        public readonly int $priority,
        public bool $ignoreCancelled = false,
    ) {}
}
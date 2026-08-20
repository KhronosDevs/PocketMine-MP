<?php

declare(strict_types=1);

namespace pocketmine\port\driving;

interface EventPort {
    public function subscribe(string $eventClass, callable $handler, int $priority = 0): void;

    public function unsubscribe(string $eventClass, callable $handler): void;

    public function emit(object $event): void;
}
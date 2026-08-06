<?php

declare(strict_types=1);

namespace pocketmine\port\driving;

interface CommandPort {
    public function register(Command $command): void;

    public function unregister(string $name): void;

    public function getCommand(string $name): ?Command;
}
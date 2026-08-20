<?php

declare(strict_types=1);

namespace pocketmine\port\driving;

interface Command {
    public function getName(): string;

    public function getDescription(): string;

    public function getUsage(): string;

    public function getAliases(): array;

    public function getPermission(): ?string;

    public function execute(CommandSender $sender, string $label, array $args): bool;
}
<?php

declare(strict_types=1);

namespace pocketmine\port\driving;

interface Plugin {
    public function getName(): string;

    public function getDescription(): PluginDescription;

    public function isEnabled(): bool;

    public function onEnable(): void;

    public function onDisable(): void;
}
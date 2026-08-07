<?php

declare(strict_types=1);

namespace pocketmine\api\scheduler;

use pocketmine\api\plugin\Plugin;
use pocketmine\core\ecs\World;
use pocketmine\core\ecs\SystemScheduler;
use pocketmine\core\ecs\System;
use pocketmine\core\ecs\SystemPhase;
use pocketmine\port\driven\ThreadingPort;

class PluginTask implements Task {
    private int $taskId = 0;
    private bool $cancelled = false;
    public int $nextRunTick = 0;

    public function __construct(
        private $callback,
        private readonly int $delay,
        private readonly int $period,
        private readonly ?Plugin $owner = null,
    ) {}

    public function getOwner(): ?Plugin {
        return $this->owner;
    }

    public function setTaskId(int $id): void {
        $this->taskId = $id;
    }

    public function getTaskId(): int {
        return $this->taskId;
    }

    public function isCancelled(): bool {
        return $this->cancelled;
    }

    public function cancel(): void {
        $this->cancelled = true;
    }

    public function run(int $currentTick): void {
        if ($this->cancelled) {
            return;
        }
        ($this->callback)($currentTick);
    }

    public function isRepeating(): bool {
        return $this->period > 0;
    }

    public function getPeriod(): int {
        return $this->period;
    }

    public function getDelay(): int {
        return $this->delay;
    }
}

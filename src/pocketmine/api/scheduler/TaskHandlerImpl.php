<?php

declare(strict_types=1);

namespace pocketmine\api\scheduler;

use pocketmine\api\plugin\Plugin;
use pocketmine\core\ecs\World;
use pocketmine\core\ecs\SystemScheduler;
use pocketmine\core\ecs\System;
use pocketmine\core\ecs\SystemPhase;
use pocketmine\port\driven\ThreadingPort;

class TaskHandlerImpl implements TaskHandler {
    public function __construct(
        private readonly Task $task,
    ) {}

    public function getTask(): Task {
        return $this->task;
    }

    public function cancel(): void {
        $this->task->cancel();
    }
}

<?php

declare(strict_types=1);

namespace pocketmine\api\scheduler;

use pocketmine\api\plugin\Plugin;
use pocketmine\core\ecs\World;
use pocketmine\core\ecs\SystemScheduler;
use pocketmine\core\ecs\System;
use pocketmine\core\ecs\SystemPhase;
use pocketmine\port\driven\ThreadingPort;

interface Task {
    public function getTaskId(): int;
    public function isCancelled(): bool;
    public function cancel(): void;
}

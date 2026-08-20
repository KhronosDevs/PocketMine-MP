<?php

declare(strict_types=1);

namespace pocketmine\plugin\api\scheduler;

use pocketmine\domain\ecs\System;
use pocketmine\domain\ecs\SystemPhase;
use pocketmine\domain\ecs\World;
use pocketmine\port\driven\ThreadingPort;

interface Task {
    public function getTaskId(): int;
    public function isCancelled(): bool;
    public function cancel(): void;
}

interface TaskHandler {
    public function getTask(): Task;
    public function cancel(): void;
}

final class PluginTask implements Task {
    private int $taskId;
    private bool $cancelled = false;

    public function __construct(
        private readonly callable $callback,
        private readonly int $delay,
        private readonly int $period,
    ) {}

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
        if ($this->cancelled) return;
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

final class AsyncTask implements Task {
    private int $taskId;
    private bool $cancelled = false;

    public function __construct(
        private readonly callable $callback,
    ) {}

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
}

final class Scheduler {
    public function __construct(
        private readonly World $world,
        private readonly ThreadingPort $threadingPort,
    ) {}

    public function scheduleRepeatingTask(callable $callback, int $period): TaskHandler {
        $task = new PluginTask($callback, -1, $period);
        // Register as a system that runs every $period ticks
        return new TaskHandlerImpl($task);
    }

    public function scheduleDelayedTask(callable $callback, int $delay): TaskHandler {
        $task = new PluginTask($callback, $delay, -1);
        return new TaskHandlerImpl($task);
    }

    public function scheduleDelayedRepeatingTask(callable $callback, int $delay, int $period): TaskHandler {
        $task = new PluginTask($callback, $delay, $period);
        return new TaskHandlerImpl($task);
    }

    public function scheduleAsyncTask(callable $callback): void {
        $task = new AsyncTask($callback);
        $this->threadingPort->submit(fn() => $task);
    }

    public function cancelTask(int $taskId): void {
        // Implementation would cancel the task
    }

    public function cancelTasks(Plugin $plugin): void {
        // Implementation would cancel all tasks for a plugin
    }

    public function registerSystem(System $system, SystemPhase $phase = SystemPhase::SEQUENTIAL): void {
        // This would be handled by the kernel's system scheduler
    }
}

final class TaskHandlerImpl implements TaskHandler {
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
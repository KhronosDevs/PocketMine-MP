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

interface TaskHandler {
    public function getTask(): Task;
    public function cancel(): void;
}

class PluginTask implements Task {
    private int $taskId = 0;
    private bool $cancelled = false;

    public function __construct(
        private $callback,
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

class Scheduler {
    private World $world;
    private SystemScheduler $systemScheduler;
    private ThreadingPort $threadingPort;
    private int $taskCounter = 0;
    private array $tasks = [];

    public function __construct() {
        $kernel = \pocketmine\Kernel::getInstance();
        $this->world = $kernel->getWorld();
        $this->systemScheduler = $kernel->getSystemScheduler();
        $this->threadingPort = $kernel->getThreadingPort();
    }

    public function scheduleRepeatingTask(callable $callback, int $period): TaskHandler {
        $taskId = ++$this->taskCounter;
        $task = new PluginTask($callback, -1, $period);
        $task->setTaskId($taskId);
        $this->tasks[$taskId] = $task;
        
        // Register as a system that runs every $period ticks
        // This would need a wrapper system
        
        return new TaskHandlerImpl($task);
    }

    public function scheduleDelayedTask(callable $callback, int $delay): TaskHandler {
        $taskId = ++$this->taskCounter;
        $task = new PluginTask($callback, $delay, -1);
        $task->setTaskId($taskId);
        $this->tasks[$taskId] = $task;
        
        return new TaskHandlerImpl($task);
    }

    public function scheduleDelayedRepeatingTask(callable $callback, int $delay, int $period): TaskHandler {
        $taskId = ++$this->taskCounter;
        $task = new PluginTask($callback, $delay, $period);
        $task->setTaskId($taskId);
        $this->tasks[$taskId] = $task;
        
        return new TaskHandlerImpl($task);
    }

    public function scheduleAsyncTask(callable $callback): void {
        $this->threadingPort->submit(fn() => $callback());
    }

    public function cancelTask(int $taskId): void {
        if (isset($this->tasks[$taskId])) {
            $this->tasks[$taskId]->cancel();
            unset($this->tasks[$taskId]);
        }
    }

    public function cancelTasks(Plugin $plugin): void {
        foreach ($this->tasks as $taskId => $task) {
            // Would check if task belongs to plugin
            $task->cancel();
        }
    }

    public function getPendingTasks(): array {
        return array_values($this->tasks);
    }

    public function registerSystem(System $system, SystemPhase $phase = SystemPhase::SEQUENTIAL): void {
        $kernel = \pocketmine\Kernel::getInstance();
        $kernel->getSystemScheduler()->register($system, $phase);
    }

    public function unregisterSystem(System $system): void {
        // Would unregister system
    }
}
<?php

declare(strict_types=1);

namespace pocketmine\api\scheduler;

use pocketmine\api\plugin\Plugin;
use pocketmine\core\ecs\World;
use pocketmine\core\ecs\SystemScheduler;
use pocketmine\core\ecs\System;
use pocketmine\core\ecs\SystemPhase;
use pocketmine\port\driven\ThreadingPort;

class Scheduler {
    private World $world;
    private SystemScheduler $systemScheduler;
    private ThreadingPort $threadingPort;
    private int $taskCounter = 0;
    private int $currentTick = 0;
    private array $tasks = [];

    public function __construct(?World $world = null, ?SystemScheduler $systemScheduler = null, ?ThreadingPort $threadingPort = null) {
        if ($world === null || $systemScheduler === null || $threadingPort === null) {
            $kernel = \pocketmine\Kernel::getInstance();
            if ($kernel === null) {
                throw new \RuntimeException("Scheduler requires a running Kernel");
            }
            $world ??= $kernel->getWorld();
            $systemScheduler ??= $kernel->getSystemScheduler();
            $threadingPort ??= $kernel->getThreadingPort();
        }
        $this->world = $world;
        $this->systemScheduler = $systemScheduler;
        $this->threadingPort = $threadingPort;

        // Tick pending tasks once per world tick (a single Scheduler instance
        // is held by the Kernel, so this registers exactly one system).
        $this->systemScheduler->register(new class($this) implements System {
            public function __construct(private readonly Scheduler $scheduler) {}

            public function run(World $world, float $deltaTime): void {
                $this->scheduler->tickTasks();
            }
        }, SystemPhase::SEQUENTIAL);
    }

    /**
     * Advance the internal tick counter and run tasks whose delay has elapsed.
     */
    public function tickTasks(): void {
        $this->currentTick++;
        foreach ($this->tasks as $taskId => $task) {
            if (!$task instanceof PluginTask || $task->isCancelled()) {
                continue;
            }
            if ($task->nextRunTick > $this->currentTick) {
                continue;
            }
            $task->run($this->currentTick);
            if ($task->isRepeating()) {
                $task->nextRunTick += $task->getPeriod();
            } else {
                unset($this->tasks[$taskId]);
            }
        }
    }

    public function scheduleRepeatingTask(callable $callback, int $period, ?Plugin $owner = null): TaskHandler {
        $taskId = ++$this->taskCounter;
        $task = new PluginTask($callback, -1, $period, $owner);
        $task->setTaskId($taskId);
        $task->nextRunTick = $this->currentTick + $period;
        $this->tasks[$taskId] = $task;
        
        return new TaskHandlerImpl($task);
    }

    public function scheduleDelayedTask(callable $callback, int $delay, ?Plugin $owner = null): TaskHandler {
        $taskId = ++$this->taskCounter;
        $task = new PluginTask($callback, $delay, -1, $owner);
        $task->setTaskId($taskId);
        $task->nextRunTick = $this->currentTick + max(1, $delay);
        $this->tasks[$taskId] = $task;
        
        return new TaskHandlerImpl($task);
    }

    public function scheduleDelayedRepeatingTask(callable $callback, int $delay, int $period, ?Plugin $owner = null): TaskHandler {
        $taskId = ++$this->taskCounter;
        $task = new PluginTask($callback, $delay, $period, $owner);
        $task->setTaskId($taskId);
        $task->nextRunTick = $this->currentTick + max(1, $delay);
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
            if ($task instanceof PluginTask && $task->getOwner() === $plugin) {
                $task->cancel();
                unset($this->tasks[$taskId]);
            }
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
        $kernel = \pocketmine\Kernel::getInstance();
        $kernel->getSystemScheduler()->unregister($system);
    }
}

<?php

declare(strict_types=1);



/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

/**
 * Task scheduling related classes
 */
namespace pocketmine\scheduler;

use pocketmine\plugin\Plugin;
use pocketmine\Server;
use pocketmine\utils\MainLogger;
use pocketmine\utils\PluginException;
use pocketmine\utils\ReversePriorityQueue;
use function count;
use function get_class;
use function is_array;
use function is_object;

class ServerScheduler{
	public static int $WORKERS = 2;
	/** @var ReversePriorityQueue<Task> */
	protected \pocketmine\utils\ReversePriorityQueue $queue;

	/** @var TaskHandler[] */
	protected array $tasks = [];

	/** @var AsyncPool */
	protected AsyncPool $asyncPool;

	/** @var int */
	private int $ids = 1;

	/** @var int */
	protected int $currentTick = 0;

	public function __construct(){
		$this->queue = new ReversePriorityQueue();
		$this->asyncPool = new AsyncPool(Server::getInstance(), self::$WORKERS, Server::getInstance()->getTickSleeper());
	}

	/**
	 * @return null|TaskHandler
	 */
	public function scheduleTask(Task $task) : ?TaskHandler{
		return $this->addTask($task, -1, -1);
	}

	/**
	 * Submits an asynchronous task to the Worker Pool
	 *
	 * @return void
	 */
	public function scheduleAsyncTask(AsyncTask $task) : void{
		$id = $this->nextId();
		$task->setTaskId($id);
		$this->asyncPool->submitTask($task);
	}

	/**
	 * Submits an asynchronous task to a specific Worker in the Pool
	 *
	 * @param int $worker
	 *
	 * @return void
	 */
	public function scheduleAsyncTaskToWorker(AsyncTask $task, int $worker) : void{
		$id = $this->nextId();
		$task->setTaskId($id);
		$this->asyncPool->submitTaskToWorker($task, $worker);
	}

	public function getAsyncPool() : AsyncPool {
		return $this->asyncPool;
	}

	public function getAsyncTaskPoolSize() : int{
		return $this->asyncPool->getSize();
	}

	/**
	 * @param int $delay
	 *
	 * @return null|TaskHandler
	 */
	public function scheduleDelayedTask(Task $task, int $delay) : ?TaskHandler{
		return $this->addTask($task, (int) $delay, -1);
	}

	/**
	 * @param int $period
	 *
	 * @return null|TaskHandler
	 */
	public function scheduleRepeatingTask(Task $task, int $period) : ?TaskHandler{
		return $this->addTask($task, -1, (int) $period);
	}

	/**
	 * @param int $delay
	 * @param int $period
	 *
	 * @return null|TaskHandler
	 */
	public function scheduleDelayedRepeatingTask(Task $task, int $delay, int $period) : ?TaskHandler{
		return $this->addTask($task, (int) $delay, (int) $period);
	}

	/**
	 * @param int $taskId
	 */
	public function cancelTask(int $taskId) : void{
		if($taskId !== null && isset($this->tasks[$taskId])){
			$this->tasks[$taskId]->cancel();
			unset($this->tasks[$taskId]);
		}
	}

	public function cancelTasks(\pocketmine\plugin\Plugin $plugin) : void{
		foreach($this->tasks as $taskId => $task){
			$ptask = $task->getTask();
			if($ptask instanceof PluginTask && $ptask->getOwner() === $plugin){
				$task->cancel();
				unset($this->tasks[$taskId]);
			}
		}
	}

	public function cancelAllTasks() : void{
		foreach($this->tasks as $task){
			$task->cancel();
		}
		$this->tasks = [];
		$this->asyncPool->removeTasks();
		while(!$this->queue->isEmpty()){
			$this->queue->extract();
		}
		$this->ids = 1;
	}

	/**
	 * @param int $taskId
	 *
	 * @return bool
	 */
	public function isQueued(int $taskId) : bool{
		return isset($this->tasks[$taskId]);
	}

	/**
	 * @param $delay
	 * @param $period
	 *
	 * @return null|TaskHandler
	 *
	 * @throws PluginException
	 */
	private function addTask(Task $task, int $delay, int $period) : ?TaskHandler{
		if($task instanceof PluginTask){
			if(!($task->getOwner() instanceof Plugin)){
				throw new PluginException("Invalid owner of PluginTask " . get_class($task));
			}elseif(!$task->getOwner()->isEnabled()){
				throw new PluginException("Plugin '" . $task->getOwner()->getName() . "' attempted to register a task while disabled");
			}
		}elseif($task instanceof CallbackTask && Server::getInstance()->getProperty("settings.deprecated-verbose", true)){
			$callable = $task->getCallable();
			if(is_array($callable)){
				if(is_object($callable[0])){
					$taskName = "Callback#" . get_class($callable[0]) . "::" . $callable[1];
				}else{
					$taskName = "Callback#" . $callable[0] . "::" . $callable[1];
				}
			}else{
				$taskName = "Callback#" . $callable;
			}
			//Server::getInstance()->getLogger()->warning("A plugin attempted to register a deprecated CallbackTask ($taskName)");
		}

		if($delay <= 0){
			$delay = -1;
		}

		if($period <= -1){
			$period = -1;
		}elseif($period < 1){
			$period = 1;
		}

		return $this->handle(new TaskHandler(get_class($task), $task, $this->nextId(), $delay, $period));
	}

	private function handle(TaskHandler $handler) : TaskHandler{
		if($handler->isDelayed()){
			$nextRun = $this->currentTick + $handler->getDelay();
		}else{
			$nextRun = $this->currentTick;
		}

		$handler->setNextRun($nextRun);
		$this->tasks[$handler->getTaskId()] = $handler;
		$this->queue->insert($handler, $nextRun);

		return $handler;
	}

	/**
	 * @param int $currentTick
	 */
	public function mainThreadHeartbeat(int $currentTick) : void{
		$this->currentTick = $currentTick;
		while($this->isReady($this->currentTick)){
			/** @var TaskHandler $task */
			$task = $this->queue->extract();
			if($task->isCancelled()){
				unset($this->tasks[$task->getTaskId()]);
				continue;
			}else{
				$task->timings->startTiming();
				try{
					$task->run($this->currentTick);
				}catch(\Throwable $e){
					Server::getInstance()->getLogger()->critical("Could not execute task " . $task->getTaskName() . ": " . $e->getMessage());
					$logger = Server::getInstance()->getLogger();
					if($logger instanceof MainLogger){
						$logger->logException($e);
					}
				}
				$task->timings->stopTiming();
			}
			if($task->isRepeating()){
				$task->setNextRun($this->currentTick + $task->getPeriod());
				$this->queue->insert($task, $this->currentTick + $task->getPeriod());
			}else{
				$task->remove();
				unset($this->tasks[$task->getTaskId()]);
			}
		}

		$this->asyncPool->collectTasks();
	}

	private function isReady(int $currentTicks) : bool{
		return count($this->tasks) > 0 && $this->queue->current()->getNextRun() <= $currentTicks;
	}

	/**
	 * @return int
	 */
	private function nextId() : int{
		return $this->ids++;
	}

}

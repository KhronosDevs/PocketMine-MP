<?php

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

namespace pocketmine\scheduler;

use pocketmine\event\Timings;
use pocketmine\Server;
use pocketmine\snooze\SleeperHandler;
use pocketmine\snooze\SleeperNotifier;

class AsyncPool { // TODO: Add better documentation for this class.

	/** @var Server */
	private $server;

	protected $size;

	/** @var AsyncTask[] */
	private $tasks = [];
	/** @var int[] */
	private $taskWorkers = [];
    /** @var array<int, array<int, AsyncTask>> */
    private $workerTasks = [];

	/** @var AsyncWorker[] */
	private $workers = [];
	/** @var int[] */
	private $workerUsage = [];

    /** @var SleeperHandler */
    private $eventLoop;

	public function __construct(Server $server, $size, SleeperHandler $eventLoop){
		$this->server = $server;
		$this->size = (int) $size;
        $this->eventLoop = $eventLoop;

		for($i = 0; $i < $this->size; ++$i){
            $workerId = $i + 1;
            $notifier = new SleeperNotifier();

            $this->workerUsage[$i] = 0;
            $this->workerTasks[$workerId] = [];
            $this->workers[$i] = new AsyncWorker($this->server->getLogger(), $workerId, $notifier);
            $this->workers[$i]->setClassLoader($this->server->getLoader());
            $this->workers[$i]->start();


            $this->eventLoop->addNotifier($notifier, function () use ($workerId) {
                $this->collectTasksFromWorker($workerId);
            });
		}
	}

	public function getSize(){
		return $this->size;
	}

    public function submitTaskToWorker(AsyncTask $task, $worker){
		if(isset($this->tasks[$task->getTaskId()]) or $task->isGarbage()){
			return;
		}

		$worker = (int) $worker;
		if($worker < 0 or $worker >= $this->size){
			throw new \InvalidArgumentException("Invalid worker $worker");
		}

		$this->tasks[$task->getTaskId()] = $task;

		$this->workers[$worker]->stack($task);
		$this->workerUsage[$worker]++;
		$this->taskWorkers[$task->getTaskId()] = $worker;
        $this->workerTasks[$worker][$task->getTaskId()] = $task;
	}

	public function submitTask(AsyncTask $task){
		if(isset($this->tasks[$task->getTaskId()]) or $task->isGarbage()){
			return;
		}

		$selectedWorker = mt_rand(0, $this->size - 1);
		$selectedTasks = $this->workerUsage[$selectedWorker];
		for($i = 0; $i < $this->size; ++$i){
			if($this->workerUsage[$i] < $selectedTasks){
				$selectedWorker = $i;
				$selectedTasks = $this->workerUsage[$i];
			}
		}

		$this->submitTaskToWorker($task, $selectedWorker);
	}

	private function removeTask(AsyncTask $task, $force = false){
		$task->setGarbage();

		if(isset($this->taskWorkers[$task->getTaskId()])){
			if(!$force and ($task->isRunning() or !$task->isGarbage())){
				return;
			}
			$this->workerUsage[$this->taskWorkers[$task->getTaskId()]]--;
			$this->workers[$this->taskWorkers[$task->getTaskId()]]->collector($task);
		}

		unset($this->tasks[$task->getTaskId()]);
        unset($this->workerTasks[$this->taskWorkers[$task->getTaskId()]][$task->getTaskId()]);
		unset($this->taskWorkers[$task->getTaskId()]);

		$task->cleanObject();
	}

	public function removeTasks(){
		do{
			foreach($this->tasks as $task){
				$task->cancelRun();
				$this->removeTask($task);
			}

			if(count($this->tasks) > 0){
				Server::microSleep(25000);
			}
		}while(count($this->tasks) > 0);

		for($i = 0; $i < $this->size; ++$i){
			$this->workerUsage[$i] = 0;
		}

        $this->workerTasks = [];
		$this->taskWorkers = [];
		$this->tasks = [];
	}

    public function collectTasksFromWorker(int $workerId) {
        foreach ($this->workerTasks[$workerId] as $task) {
            $this->processTask($task);
        }
    }

	public function collectTasks(){
		Timings::$schedulerAsyncTimer->startTiming();

		foreach($this->tasks as $task){
            $this->processTask($task);
		}

		Timings::$schedulerAsyncTimer->stopTiming();
	}

    /**
     * @param AsyncTask $task
     * @return void
     */
    private function processTask(AsyncTask $task) {
        if ($task->isFinished() and !$task->isRunning() and !$task->isCrashed()) {

            if (!$task->hasCancelledRun()) {
                $task->onCompletion($this->server);
            }

            $this->removeTask($task);
        } elseif ($task->isTerminated() or $task->isCrashed()) {
            $this->server->getLogger()->critical("Could not execute asynchronous task " . (new \ReflectionClass($task))->getShortName() . ": Task crashed");
            $this->removeTask($task, true);
        }
    }
}

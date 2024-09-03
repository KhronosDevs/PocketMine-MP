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

namespace pocketmine\scheduler;

use pocketmine\snooze\SleeperNotifier;
use pocketmine\Worker;
use function gc_enable;
use function ini_set;

class AsyncWorker extends Worker{

	/** @var SleeperNotifier */
	private $notifier = null;
	private $logger;
	private $id;

	public function __construct(\ThreadedLogger $logger, $id, SleeperNotifier $notifier){
		$this->logger = $logger;
		$this->id = $id;
		$this->notifier = $notifier;
	}

	public function run(){
		$this->registerClassLoader();
		gc_enable();
		ini_set("memory_limit", '-1');

		global $store;
		$store = [];
	}

	public function handleException(\Throwable $e){
		$this->logger->logException($e);
	}

	public function getThreadName(){
		return "Asynchronous Worker #" . $this->id;
	}

	public function getId() {
		return $this->id;
	}

	public function getNotifier() : SleeperNotifier {
		return $this->notifier;
	}

}

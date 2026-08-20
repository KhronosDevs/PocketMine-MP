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

namespace pocketmine\plugin;

use pocketmine\event\Cancellable;
use pocketmine\event\Event;
use pocketmine\event\Listener;
use pocketmine\event\TimingsHandler;

class RegisteredListener{

	/** @var Listener */
	private Listener $listener;

	/** @var int */
	private int $priority;

	/** @var Plugin */
	private Plugin $plugin;

	/** @var EventExecutor */
	private EventExecutor $executor;

	/** @var bool */
	private bool $ignoreCancelled;

	/** @var TimingsHandler */
	private TimingsHandler $timings;

	/**
	 * @param int     $priority
	 * @param boolean $ignoreCancelled
	 */
	public function __construct(Listener $listener, EventExecutor $executor, int $priority, Plugin $plugin, bool $ignoreCancelled, TimingsHandler $timings){
		$this->listener = $listener;
		$this->priority = $priority;
		$this->plugin = $plugin;
		$this->executor = $executor;
		$this->ignoreCancelled = $ignoreCancelled;
		$this->timings = $timings;
	}

	/**
	 * @return Listener
	 */
	public function getListener() : Listener{
		return $this->listener;
	}

	/**
	 * @return Plugin
	 */
	public function getPlugin() : Plugin{
		return $this->plugin;
	}

	/**
	 * @return int
	 */
	public function getPriority() : int{
		return $this->priority;
	}

	public function callEvent(Event $event) : void{
		if($event instanceof Cancellable && $event->isCancelled() && $this->isIgnoringCancelled()){
			return;
		}
		$this->timings->startTiming();
		$this->executor->execute($this->listener, $event);
		$this->timings->stopTiming();
	}

	public function __destruct(){
		$this->timings->remove();
	}

	/**
	 * @return bool
	 */
	public function isIgnoringCancelled() : bool{
		return $this->ignoreCancelled === true;
	}
}

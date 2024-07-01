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

declare(strict_types=1);

namespace pocketmine\snooze;

/**
 * Notifiers are Threaded objects which can be attached to threaded sleepers in order to wake them up. They also record
 * state so that the main thread handler can determine which notifier woke up the sleeper.
 */
class SleeperNotifier extends \Threaded{
	/** @var ThreadedSleeper */
	private $threadedSleeper;

	/** @var int */
	private $sleeperId;

	/** @var bool */
	private $notification = false;

	public final function attachSleeper(ThreadedSleeper $sleeper, int $id) {
		$this->threadedSleeper = $sleeper;
		$this->sleeperId = $id;
	}

	public final function getSleeperId(): int {
		return $this->sleeperId;
	}

	/**
	 * Call this method from other threads to wake up the main server thread.
	 */
	public final function wakeupSleeper() {
		assert($this->threadedSleeper !== null);

		$this->synchronized(function() {
            if($this->notification) return;

            $this->notification = true;
            $this->threadedSleeper->wakeup();
        });
	}

	public final function hasNotification(): bool{
		return $this->notification;
	}

	public final function clearNotification() {
		$this->synchronized(function()  {
			//this has to be synchronized to avoid races with waking up
			$this->notification = false;
		});
	}
}
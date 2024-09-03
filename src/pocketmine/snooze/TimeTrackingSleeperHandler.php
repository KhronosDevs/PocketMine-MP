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

use pocketmine\event\TimingsHandler;
use function microtime;

final class TimeTrackingSleeperHandler extends SleeperHandler{

	private $notificationProcessingTimeNs = 0;
	private $timings;

	public function __construct(TimingsHandler $timings){
		parent::__construct();
		$this->timings = $timings;
	}

	public function getNotificationProcessingTime() : int {
		return $this->notificationProcessingTimeNs;
	}

	public function resetNotificationProcessingTime() {
		$this->notificationProcessingTimeNs = 0;
	}

	public function processNotifications() {
		$startTime = microtime(true);
		$this->timings->startTiming();
		try{
			parent::processNotifications();
		} finally {
			$this->notificationProcessingTimeNs += microtime(true) - $startTime;
			$this->timings->stopTiming();
		}
	}
}

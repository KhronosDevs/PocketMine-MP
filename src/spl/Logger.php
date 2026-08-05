<?php



/*
 * PocketMine Standard PHP Library
 * Copyright (C) 2014 PocketMine Team <https://github.com/PocketMine/PocketMine-SPL>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
*/

declare(strict_types=1);

interface Logger{

	/**
	 * System is unusable
	 */
	public function emergency(string $message);

	/**
	 * Action must me taken immediately
	 */
	public function alert(string $message);

	/**
	 * Critical conditions
	 */
	public function critical(string $message);

	/**
	 * Runtime errors that do not require immediate action but should typically
	 * be logged and monitored.
	 */
	public function error(string $message);

	/**
	 * Exceptional occurrences that are not errors.
	 *
	 * Example: Use of deprecated APIs, poor use of an API, undesirable things
	 * that are not necessarily wrong.
	 */
	public function warning(string $message);

	/**
	 * Normal but significant events.
	 */
	public function notice(string $message);

	/**
	 * Inersting events.
	 */
	public function info(string $message);

	/**
	 * Detailed debug information.
	 */
	public function debug(string $message);

	/**
	 * Logs with an arbitrary level.
	 */
	public function log(int|string $level, string $message);

	/**
	 * Logs a Throwable object
	 */
	public function logException(Throwable $e, ?array $trace = null);
}

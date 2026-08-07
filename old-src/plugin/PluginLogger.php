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

use LogLevel;
use pocketmine\Server;
use function spl_object_hash;

class PluginLogger implements \AttachableLogger{

	private string $pluginName;

	/** @var \LoggerAttachment[] */
	private array $attachments = [];

	public function addAttachment(\LoggerAttachment $attachment) : void{
		$this->attachments[spl_object_hash($attachment)] = $attachment;
	}

	public function removeAttachment(\LoggerAttachment $attachment) : void{
		unset($this->attachments[spl_object_hash($attachment)]);
	}

	public function removeAttachments() : void{
		$this->attachments = [];
	}

	public function getAttachments() : array{
		return $this->attachments;
	}

	public function __construct(Plugin $context){
		$prefix = $context->getDescription()->getPrefix();
		$this->pluginName = $prefix != null ? "[$prefix] " : "[" . $context->getDescription()->getName() . "] ";
	}

	public function emergency(string $message) : void{
		$this->log(LogLevel::EMERGENCY, $message);
	}

	public function alert(string $message) : void{
		$this->log(LogLevel::ALERT, $message);
	}

	public function critical(string $message) : void{
		$this->log(LogLevel::CRITICAL, $message);
	}

	public function error(string $message) : void{
		$this->log(LogLevel::ERROR, $message);
	}

	public function warning(string $message) : void{
		$this->log(LogLevel::WARNING, $message);
	}

	public function notice(string $message) : void{
		$this->log(LogLevel::NOTICE, $message);
	}

	public function info(string $message) : void{
		$this->log(LogLevel::INFO, $message);
	}

	public function debug(string $message) : void{
		$this->log(LogLevel::DEBUG, $message);
	}

	public function logException(\Throwable $e, ?array $trace = null) : void{
		Server::getInstance()->getLogger()->logException($e, $trace);
	}

	public function log(int|string $level, string $message) : void{
		Server::getInstance()->getLogger()->log($level, $this->pluginName . $message);
		foreach($this->attachments as $attachment){
			$attachment->log($level, $message);
		}
	}
}

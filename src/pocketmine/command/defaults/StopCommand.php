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

namespace pocketmine\command\defaults;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\TranslationContainer;
use function array_shift;
use function implode;

class StopCommand extends VanillaCommand{

	public function __construct($name){
		parent::__construct(
			$name,
			"%pocketmine.command.stop.description",
			"%commands.stop.usage"
		);
		$this->setPermission("pocketmine.command.stop");
	}

	public function execute(CommandSender $sender, $currentAlias, array $args){
		if(!$this->testPermission($sender)){
			return true;
		}
		$restart = false;
		if(isset($args[0])){
			if($args[0] == 'force'){
				$restart = true;
				array_shift($args);
			}else{
				$restart = false;
			}
		}
		Command::broadcastCommandMessage($sender, new TranslationContainer("commands.stop.start"));
		$msg = implode(" ", $args);
		$sender->getServer()->shutdown($restart, $msg);

		return true;
	}
}

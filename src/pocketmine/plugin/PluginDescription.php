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

use pocketmine\permission\Permission;
use pocketmine\utils\PluginException;
use function constant;
use function defined;
use function is_array;
use function preg_replace;
use function str_replace;
use function stripos;
use function strtoupper;
use function yaml_parse;

class PluginDescription{
	private string $name;
	private string $main;
	private array $api;
	private array $depend = [];
	private array $softDepend = [];
	private array $loadBefore = [];
	private $version;
	private array $commands = [];
	private ?string $description = null;
	private array $authors = [];
	private ?string $website = null;
	private ?string $prefix = null;
	private int $order = PluginLoadOrder::POSTWORLD;

	private array $geniapi;

	/** @var Permission[] */
	private array $permissions = [];

	/**
	 * @param string|array $yamlString
	 */
	public function __construct(string|array $yamlString){
		$this->loadMap(!is_array($yamlString) ? yaml_parse($yamlString) : $yamlString);
	}

	/**
	 * @throws PluginException
	 */
	private function loadMap(array $plugin){
		$this->name = preg_replace("[^A-Za-z0-9 _.-]", "", $plugin["name"]);
		if($this->name === ""){
			throw new PluginException("Invalid PluginDescription name");
		}
		$this->name = str_replace(" ", "_", $this->name);
		$this->version = $plugin["version"];
		$this->main = $plugin["main"];
		$this->api = !is_array($plugin["api"]) ? [$plugin["api"]] : $plugin["api"];
		if(!isset($plugin["geniapi"])){
			$this->geniapi = ["1.0.0"];
		}else{
			$this->geniapi = !is_array($plugin["geniapi"]) ? [$plugin["geniapi"]] : $plugin["geniapi"];
		}

		if(stripos($this->main, "pocketmine\\") === 0){
			throw new PluginException("Invalid PluginDescription main, cannot start within the PocketMine namespace");
		}

		if(isset($plugin["commands"]) && is_array($plugin["commands"])){
			$this->commands = $plugin["commands"];
		}

		if(isset($plugin["depend"])){
			$this->depend = (array) $plugin["depend"];
		}
		if(isset($plugin["softdepend"])){
			$this->softDepend = (array) $plugin["softdepend"];
		}
		if(isset($plugin["loadbefore"])){
			$this->loadBefore = (array) $plugin["loadbefore"];
		}

		if(isset($plugin["website"])){
			$this->website = $plugin["website"];
		}
		if(isset($plugin["description"])){
			$this->description = $plugin["description"];
		}
		if(isset($plugin["prefix"])){
			$this->prefix = $plugin["prefix"];
		}
		if(isset($plugin["load"])){
			$order = strtoupper($plugin["load"]);
			if(!defined(PluginLoadOrder::class . "::" . $order)){
				throw new PluginException("Invalid PluginDescription load");
			}else{
				$this->order = constant(PluginLoadOrder::class . "::" . $order);
			}
		}
		$this->authors = [];
		if(isset($plugin["author"])){
			$this->authors[] = $plugin["author"];
		}
		if(isset($plugin["authors"])){
			foreach($plugin["authors"] as $author){
				$this->authors[] = $author;
			}
		}

		if(isset($plugin["permissions"])){
			$this->permissions = Permission::loadPermissions($plugin["permissions"]);
		}
	}

	/**
	 * @return string
	 */
	public function getFullName() : string{
		return $this->name . " v" . $this->version;
	}

	/**
	 * @return array
	 */
	public function getCompatibleApis() : array{
		return $this->api;
	}

	/**
	 * @return array
	 */
	public function getCompatibleGeniApis() : array{
		return $this->geniapi;
	}

	/**
	 * @return array
	 */
	public function getAuthors() : array{
		return $this->authors;
	}

	/**
	 * @return string
	 */
	public function getPrefix() : ?string{
		return $this->prefix;
	}

	/**
	 * @return array
	 */
	public function getCommands() : array{
		return $this->commands;
	}

	/**
	 * @return array
	 */
	public function getDepend() : array{
		return $this->depend;
	}

	/**
	 * @return string
	 */
	public function getDescription() : ?string{
		return $this->description;
	}

	/**
	 * @return array
	 */
	public function getLoadBefore() : array{
		return $this->loadBefore;
	}

	/**
	 * @return string
	 */
	public function getMain() : string{
		return $this->main;
	}

	public function getName() : string{
		return $this->name;
	}

	/**
	 * @return int
	 */
	public function getOrder() : int{
		return $this->order;
	}

	/**
	 * @return Permission[]
	 */
	public function getPermissions() : array{
		return $this->permissions;
	}

	/**
	 * @return array
	 */
	public function getSoftDepend() : array{
		return $this->softDepend;
	}

	/**
	 * @return string
	 */
	public function getVersion(){
		return $this->version;
	}

	/**
	 * @return string
	 */
	public function getWebsite() : ?string{
		return $this->website;
	}
}

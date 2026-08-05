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

namespace pocketmine\utils;

use pocketmine\scheduler\FileWriteTask;
use pocketmine\Server;
use function array_change_key_case;
use function array_keys;
use function array_pop;
use function array_shift;
use function basename;
use function count;
use function date;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_array;
use function is_bool;
use function json_decode;
use function json_encode;
use function preg_match_all;
use function preg_replace;
use function serialize;
use function str_replace;
use function strlen;
use function strtolower;
use function substr;
use function trim;
use function unserialize;
use function yaml_emit;
use function yaml_parse;
use const CASE_LOWER;
use const JSON_BIGINT_AS_STRING;
use const JSON_PRETTY_PRINT;
use const YAML_UTF8_ENCODING;

/**
 * Class Config
 *
 * Config Class for simple config manipulation of multiple formats.
 */
class Config{
	const DETECT = -1; //Detect by file extension
	const PROPERTIES = 0; // .properties
	const CNF = Config::PROPERTIES; // .cnf
	const JSON = 1; // .js, .json
	const YAML = 2; // .yml, .yaml
	//const EXPORT = 3; // .export, .xport
	const SERIALIZED = 4; // .sl
	const ENUM = 5; // .txt, .list, .enum
	const ENUMERATION = Config::ENUM;

	/** @var array */
	private array $config = [];

	private array $nestedCache = [];

	/** @var string */
	private string $file;
	/** @var boolean */
	private bool $correct = false;
	/** @var integer */
	private int $type = Config::DETECT;

	public static array $formats = [
		"properties" => Config::PROPERTIES,
		"cnf" => Config::CNF,
		"conf" => Config::CNF,
		"config" => Config::CNF,
		"json" => Config::JSON,
		"js" => Config::JSON,
		"yml" => Config::YAML,
		"yaml" => Config::YAML,
		//"export" => Config::EXPORT,
		//"xport" => Config::EXPORT,
		"sl" => Config::SERIALIZED,
		"serialize" => Config::SERIALIZED,
		"txt" => Config::ENUM,
		"list" => Config::ENUM,
		"enum" => Config::ENUM,
	];

	/**
	 * @param string $file     Path of the file to be loaded
	 * @param int    $type     Config type to load, -1 by default (detect)
	 * @param array  $default  Array with the default values that will be written to the file if it did not exist
	 * @param null   &$correct Sets correct to true if everything has been loaded correctly
	 */
	public function __construct(string $file, int $type = Config::DETECT, array $default = [], ?bool &$correct = null){
		$this->load($file, $type, $default);
		$correct = $this->correct;
	}

	/**
	 * Removes all the changes in memory and loads the file again
	 */
	public function reload() : void{
		$this->config = [];
		$this->nestedCache = [];
		$this->correct = false;
		$this->load($this->file, $this->type);
	}

	public static function fixYAMLIndexes(string $str) : string{
		return (string) preg_replace("#^([ ]*)([a-zA-Z_]{1}[ ]*)\:$#m", "$1\"$2\":", $str);
	}

	/**
	 * @param string $file
	 * @param int    $type
	 * @param array  $default
	 *
	 * @return bool
	 */
	public function load(string $file, int $type = Config::DETECT, array $default = []) : bool{
		$this->correct = true;
		$this->type = $type;
		$this->file = $file;
		if(!is_array($default)){
			$default = [];
		}
		if(!file_exists($file)){
			$this->config = $default;
			$this->save();
		}else{
			if($this->type === Config::DETECT){
				$extension = explode(".", basename($this->file));
				$extension = strtolower(trim(array_pop($extension)));
				if(isset(Config::$formats[$extension])){
					$this->type = Config::$formats[$extension];
				}else{
					$this->correct = false;
				}
			}
			if($this->correct === true){
				$content = file_get_contents($this->file);
				if($content === false){
					$content = "";
				}
				switch($this->type){
					case Config::PROPERTIES:
					case Config::CNF:
						$this->parseProperties($content);
						break;
					case Config::JSON:
						$parsed = json_decode($content, true);
						$this->config = is_array($parsed) ? $parsed : [];
						break;
					case Config::YAML:
						$content = self::fixYAMLIndexes($content);
						$parsed = yaml_parse($content);
						$this->config = is_array($parsed) ? $parsed : [];
						break;
					case Config::SERIALIZED:
						$parsed = unserialize($content);
						$this->config = is_array($parsed) ? $parsed : [];
						break;
					case Config::ENUM:
						$this->parseList($content);
						break;
					default:
						$this->correct = false;

						return false;
				}
				if(!is_array($this->config)){
					$this->config = $default;
				}
				if($this->fillDefaults($default, $this->config) > 0){
					$this->save();
				}
			}else{
				return false;
			}
		}

		return true;
	}

	public function check() : bool{
		return $this->correct === true;
	}

	public function save(bool $async = false) : bool{
		if($this->correct === true){
			try{
				$content = null;
				switch($this->type){
					case Config::PROPERTIES:
					case Config::CNF:
						$content = $this->writeProperties();
						break;
					case Config::JSON:
						$content = json_encode($this->config, JSON_PRETTY_PRINT | JSON_BIGINT_AS_STRING);
						break;
					case Config::YAML:
						$content = yaml_emit($this->config, YAML_UTF8_ENCODING);
						break;
					case Config::SERIALIZED:
						$content = serialize($this->config);
						break;
					case Config::ENUM:
						$content = implode("\r\n", array_keys($this->config));
						break;
				}

				if($async){
					Server::getInstance()->getScheduler()->scheduleAsyncTask(new FileWriteTask($this->file, $content));
				}else{
					file_put_contents($this->file, $content);
				}
			}catch(\Throwable $e){
				$logger = Server::getInstance()->getLogger();
				$logger->critical("Could not save Config " . $this->file . ": " . $e->getMessage());
				if(\pocketmine\DEBUG > 1 && $logger instanceof MainLogger){
					$logger->logException($e);
				}
			}

			return true;
		}else{
			return false;
		}
	}

	public function __get(string $k) : mixed{
		return $this->get($k);
	}

	public function __set(string $k, mixed $v) : void{
		$this->set($k, $v);
	}

	public function __isset(string $k) : bool{
		return $this->exists($k);
	}

	public function __unset(string $k) : void{
		$this->remove($k);
	}

	public function setNested(string $key, mixed $value) : void{
		$vars = explode(".", $key);
		$base = array_shift($vars);

		if(!isset($this->config[$base])){
			$this->config[$base] = [];
		}

		$base = &$this->config[$base];

		while(count($vars) > 0){
			$baseKey = array_shift($vars);
			if(!isset($base[$baseKey])){
				$base[$baseKey] = [];
			}
			$base = &$base[$baseKey];
		}

		$base = $value;
		$this->nestedCache[$key] = $value;
	}

	public function getNested(string $key, mixed $default = null) : mixed{
		if(isset($this->nestedCache[$key])){
			return $this->nestedCache[$key];
		}

		$vars = explode(".", $key);
		$base = array_shift($vars);
		if(isset($this->config[$base])){
			$base = $this->config[$base];
		}else{
			return $default;
		}

		while(count($vars) > 0){
			$baseKey = array_shift($vars);
			if(is_array($base) && isset($base[$baseKey])){
				$base = $base[$baseKey];
			}else{
				return $default;
			}
		}

		return $this->nestedCache[$key] = $base;
	}

	public function get(string $k, mixed $default = false) : mixed{
		return ($this->correct && isset($this->config[$k])) ? $this->config[$k] : $default;
	}

	/**
	 * @param string $k key to be set
	 * @param mixed  $v value to set key
	 */
	public function set(string $k, mixed $v = true) : void{
		$this->config[$k] = $v;
		foreach($this->nestedCache as $nestedKey => $nvalue){
			if(substr($nestedKey, 0, strlen($k) + 1) === ($k . ".")){
				unset($this->nestedCache[$nestedKey]);
			}
		}
	}

	public function setAll(array $v) : void{
		$this->config = $v;
	}

	/**
	 * @param string $k
	 * @param bool   $lowercase If set, searches Config in single-case / lowercase.
	 *
	 * @return boolean
	 */
	public function exists(string $k, bool $lowercase = false) : bool{
		if($lowercase === true){
			$k = strtolower($k); //Convert requested  key to lower
			$array = array_change_key_case($this->config, CASE_LOWER); //Change all keys in array to lower
			return isset($array[$k]); //Find $k in modified array
		}else{
			return isset($this->config[$k]);
		}
	}

	public function remove(string $k) : void{
		unset($this->config[$k]);
	}

	/**
	 * @param bool $keys
	 *
	 * @return array
	 */
	public function getAll(bool $keys = false) : array{
		return ($keys === true ? array_keys($this->config) : $this->config);
	}

	public function setDefaults(array $defaults) : void{
		$this->fillDefaults($defaults, $this->config);
	}

	private function fillDefaults(array $default, array &$data) : int{
		$changed = 0;
		foreach($default as $k => $v){
			if(is_array($v)){
				if(!isset($data[$k]) || !is_array($data[$k])){
					$data[$k] = [];
				}
				$changed += $this->fillDefaults($v, $data[$k]);
			}elseif(!isset($data[$k])){
				$data[$k] = $v;
				++$changed;
			}
		}

		return $changed;
	}

	private function parseList(string $content) : void{
		foreach(explode("\n", trim(str_replace("\r\n", "\n", $content))) as $v){
			$v = trim($v);
			if($v == ""){
				continue;
			}
			$this->config[$v] = true;
		}
	}

	private function writeProperties() : string{
		$content = "#Properties Config file\r\n#" . date("D M j H:i:s T Y") . "\r\n";
		foreach($this->config as $k => $v){
			if(is_bool($v) === true){
				$v = $v === true ? "on" : "off";
			}elseif(is_array($v)){
				$v = implode(";", $v);
			}
			$content .= $k . "=" . $v . "\r\n";
		}

		return $content;
	}

	private function parseProperties(string $content) : void{
		if(preg_match_all('/([a-zA-Z0-9\-_\.]*)=([^\r\n]*)/u', $content, $matches) > 0){ //false or 0 matches
			foreach($matches[1] as $i => $k){
				$v = trim($matches[2][$i]);
				switch(strtolower($v)){
					case "on":
					case "true":
					case "yes":
						$v = true;
						break;
					case "off":
					case "false":
					case "no":
						$v = false;
						break;
				}
				if(isset($this->config[$k])){
					MainLogger::getLogger()->debug("[Config] Repeated property " . $k . " on file " . $this->file);
				}
				$this->config[$k] = $v;
			}
		}
	}

}

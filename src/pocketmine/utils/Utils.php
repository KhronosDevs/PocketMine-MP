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

/**
 * Various Utilities used around the code
 */
namespace pocketmine\utils;

use pocketmine\ThreadManager;
use function array_merge;
use function bin2hex;
use function chunk_split;
use function count;
use function curl_close;
use function curl_exec;
use function curl_init;
use function curl_setopt;
use function dechex;
use function exec;
use function file;
use function file_exists;
use function file_get_contents;
use function get_current_user;
use function get_loaded_extensions;
use function getenv;
use function gettype;
use function hexdec;
use function implode;
use function is_array;
use function is_string;
use function memory_get_usage;
use function ord;
use function php_uname;
use function phpversion;
use function preg_grep;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function random_bytes;
use function sha1;
use function spl_object_hash;
use function str_pad;
use function str_split;
use function strip_tags;
use function stripos;
use function strlen;
use function strpos;
use function strtolower;
use function sys_get_temp_dir;
use function trim;
use const CURLOPT_AUTOREFERER;
use const CURLOPT_CONNECTTIMEOUT;
use const CURLOPT_FOLLOWLOCATION;
use const CURLOPT_FORBID_REUSE;
use const CURLOPT_FRESH_CONNECT;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POST;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_SSL_VERIFYHOST;
use const CURLOPT_SSL_VERIFYPEER;
use const CURLOPT_TIMEOUT;
use const PHP_EOL;
use const PHP_INT_MAX;
use const PHP_INT_SIZE;
use const PHP_MAXPATHLEN;
use const STR_PAD_LEFT;
use const STR_PAD_RIGHT;

/**
 * Big collection of functions
 */
class Utils{
	public static $online = true;
	public static $ip = false;
	public static $os;
	private static $serverUniqueId = null;

	/**
	 * Generates an unique identifier to a callable
	 *
	 * @return string
	 */
	public static function getCallableIdentifier(callable $variable){
		if(is_array($variable)){
			return sha1(strtolower(spl_object_hash($variable[0])) . "::" . strtolower($variable[1]));
		}else{
			return sha1(strtolower($variable));
		}
	}

	/**
	 * Gets this machine / server instance unique ID
	 * Returns a hash, the first 32 characters (or 16 if raw)
	 * will be an identifier that won't change frequently.
	 * The rest of the hash will change depending on other factors.
	 *
	 * @param string $extra optional, additional data to identify the machine
	 *
	 * @return UUID
	 */
	public static function getMachineUniqueId($extra = ""){
		if(self::$serverUniqueId !== null && $extra === ""){
			return self::$serverUniqueId;
		}

		$machine = php_uname("a");
		$machine .= file_exists("/proc/cpuinfo") ? implode(preg_grep("/(model name|Processor|Serial)/", file("/proc/cpuinfo"))) : "";
		$machine .= sys_get_temp_dir();
		$machine .= $extra;
		$os = Utils::getOS();
		if($os === "win"){
			@exec("ipconfig /ALL", $mac);
			$mac = implode("\n", $mac);
			if(preg_match_all("#Physical Address[. ]{1,}: ([0-9A-F\\-]{17})#", $mac, $matches)){
				foreach($matches[1] as $i => $v){
					if($v == "00-00-00-00-00-00"){
						unset($matches[1][$i]);
					}
				}
				$machine .= implode(" ", $matches[1]); //Mac Addresses
			}
		}elseif($os === "linux"){
			if(file_exists("/etc/machine-id")){
				$machine .= file_get_contents("/etc/machine-id");
			}else{
				@exec("ifconfig", $mac);
				$mac = implode("\n", $mac);
				if(preg_match_all("#HWaddr[ \t]{1,}([0-9a-f:]{17})#", $mac, $matches)){
					foreach($matches[1] as $i => $v){
						if($v == "00:00:00:00:00:00"){
							unset($matches[1][$i]);
						}
					}
					$machine .= implode(" ", $matches[1]); //Mac Addresses
				}
			}
		}elseif($os === "android"){
			$machine .= @file_get_contents("/system/build.prop");
		}elseif($os === "mac"){
			$machine .= `system_profiler SPHardwareDataType | grep UUID`;
		}
		$data = $machine . PHP_MAXPATHLEN;
		$data .= PHP_INT_MAX;
		$data .= PHP_INT_SIZE;
		$data .= get_current_user();
		foreach(get_loaded_extensions() as $ext){
			$data .= $ext . ":" . phpversion($ext);
		}

		$uuid = UUID::fromData($machine, $data);

		if($extra === ""){
			self::$serverUniqueId = $uuid;
		}

		return $uuid;
	}

	/**
	 * Gets the External IP using an external service, it is cached
	 *
	 * @param bool $force default false, force IP check even when cached
	 *
	 * @return string
	 */

	public static function getIP($force = false){
		if(Utils::$online === false){
			return false;
		}elseif(Utils::$ip !== false && $force !== true){
			return Utils::$ip;
		}
		$ip = trim(strip_tags(Utils::getURL("https://api.ipify.org")));
		if($ip){
			Utils::$ip = $ip;
		}else{
			$ip = Utils::getURL("http://www.checkip.org/");
			if(preg_match('#">([0-9a-fA-F\:\.]*)</span>#', $ip, $matches) > 0){
				Utils::$ip = $matches[1];
			}else{
				$ip = Utils::getURL("http://checkmyip.org/");
				if(preg_match('#Your IP address is ([0-9a-fA-F\:\.]*)#', $ip, $matches) > 0){
					Utils::$ip = $matches[1];
				}else{
					$ip = trim(Utils::getURL("http://ifconfig.me/ip"));
					if($ip != ""){
						Utils::$ip = $ip;
					}else{
						return false;
					}
				}
			}
		}

		return Utils::$ip;

	}

	/**
	 * Returns the current Operating System
	 * Windows => win
	 * MacOS => mac
	 * iOS => ios
	 * Android => android
	 * Linux => Linux
	 * BSD => bsd
	 * Other => other
	 *
	 * @return string
	 */
	public static function getOS($recalculate = false){
		if(self::$os === null || $recalculate){
			$uname = php_uname("s");
			if(stripos($uname, "Darwin") !== false){
				if(strpos(php_uname("m"), "iP") === 0){
					self::$os = "ios";
				}else{
					self::$os = "mac";
				}
			}elseif(stripos($uname, "Win") !== false || $uname === "Msys"){
				self::$os = "win";
			}elseif(stripos($uname, "Linux") !== false){
				if(@file_exists("/system/build.prop")){
					self::$os = "android";
				}else{
					self::$os = "linux";
				}
			}elseif(stripos($uname, "BSD") !== false || $uname === "DragonFly"){
				self::$os = "bsd";
			}else{
				self::$os = "other";
			}
		}

		return self::$os;
	}

	public static function getRealMemoryUsage(){
		$stack = 0;
		$heap = 0;

		if(Utils::getOS() === "linux" || Utils::getOS() === "android"){
			$mappings = file("/proc/self/maps");
			foreach($mappings as $line){
				if(preg_match("#([a-z0-9]+)\\-([a-z0-9]+) [rwxp\\-]{4} [a-z0-9]+ [^\\[]*\\[([a-zA-z0-9]+)\\]#", trim($line), $matches) > 0){
					if(strpos($matches[3], "heap") === 0){
						$heap += hexdec($matches[2]) - hexdec($matches[1]);
					}elseif(strpos($matches[3], "stack") === 0){
						$stack += hexdec($matches[2]) - hexdec($matches[1]);
					}
				}
			}
		}

		return [$heap, $stack];
	}

	public static function getMemoryUsage($advanced = false){
		$reserved = memory_get_usage();
		$VmSize = null;
		$VmRSS = null;
		if(Utils::getOS() === "linux" || Utils::getOS() === "android"){
			$status = file_get_contents("/proc/self/status");
			if(preg_match("/VmRSS:[ \t]+([0-9]+) kB/", $status, $matches) > 0){
				$VmRSS = $matches[1] * 1024;
			}

			if(preg_match("/VmSize:[ \t]+([0-9]+) kB/", $status, $matches) > 0){
				$VmSize = $matches[1] * 1024;
			}
		}

		//TODO: more OS

		if($VmRSS === null){
			$VmRSS = memory_get_usage();
		}

		if(!$advanced){
			return $VmRSS;
		}

		if($VmSize === null){
			$VmSize = memory_get_usage(true);
		}

		return [$reserved, $VmRSS, $VmSize];
	}

	public static function getThreadCount(){
		if(Utils::getOS() === "linux" || Utils::getOS() === "android"){
			if(preg_match("/Threads:[ \t]+([0-9]+)/", file_get_contents("/proc/self/status"), $matches) > 0){
				return (int) $matches[1];
			}
		}
		//TODO: more OS

		return count(ThreadManager::getInstance()->getAll()) + 3; //RakLib + MainLogger + Main Thread
	}

	public static function getCoreCount($recalculate = false){
		static $processors = 0;

		if($processors > 0 && !$recalculate){
			return $processors;
		}else{
			$processors = 0;
		}

		switch(Utils::getOS()){
			case "linux":
			case "android":
				if(file_exists("/proc/cpuinfo")){
					foreach(file("/proc/cpuinfo") as $l){
						if(preg_match('/^processor[ \t]*:[ \t]*[0-9]+$/m', $l) > 0){
							++$processors;
						}
					}
				}else{
					if(preg_match("/^([0-9]+)\\-([0-9]+)$/", trim(@file_get_contents("/sys/devices/system/cpu/present")), $matches) > 0){
						$processors = (int) ($matches[2] - $matches[1]);
					}
				}
				break;
			case "bsd":
			case "mac":
				$processors = (int) `sysctl -n hw.ncpu`;
				$processors = (int) `sysctl -n hw.ncpu`;
				break;
			case "win":
				$processors = (int) getenv("NUMBER_OF_PROCESSORS");
				break;
		}
		return $processors;
	}

	/**
	 * Returns a prettified hexdump
	 *
	 * @param string $bin
	 *
	 * @return string
	 */
	public static function hexdump($bin){
		$output = "";
		$bin = str_split($bin, 16);
		foreach($bin as $counter => $line){
			$hex = chunk_split(chunk_split(str_pad(bin2hex($line), 32, " ", STR_PAD_RIGHT), 2, " "), 24, " ");
			$ascii = preg_replace('#([^\x20-\x7E])#', ".", $line);
			$output .= str_pad(dechex($counter << 4), 4, "0", STR_PAD_LEFT) . "  " . $hex . " " . $ascii . PHP_EOL;
		}

		return $output;
	}

	/**
	 * Returns a string that can be printed, replaces non-printable characters
	 *
	 * @param $str
	 *
	 * @return string
	 */
	public static function printable($str){
		if(!is_string($str)){
			return gettype($str);
		}

		return preg_replace('#([^\x20-\x7E])#', '.', $str);
	}

	/**
	 * This function tries to get all the entropy available in PHP, and distills it to get a good RNG.
	 *
	 * This function simply forwards to the PHP random_bytes function.
	 *
	 * @param int    $length       default 16, Number of bytes to generate
	 * @param bool   $secure       default true, Generate secure distilled bytes, slower
	 * @param bool   $raw          default true, returns a binary string if true, or an hexadecimal one
	 * @param string $startEntropy default null, adds more initial entropy
	 * @param int    &$rounds      Will be set to the number of rounds taken
	 * @param int    &$drop        Will be set to the amount of dropped bytes
	 *
	 * @deprecated prefer PHP 7 random_bytes()
	 * @return string
	 */
	public static function getRandomBytes($length = 16, $secure = true, $raw = true, $startEntropy = "", &$rounds = 0, &$drop = 0){
		$raw_output = random_bytes($length);
		if ($raw) {
			return $raw_output;
		} else {
			return bin2hex($raw_output);
		}
	}

	/*
	public static function angle3D($pos1, $pos2){
		$X = $pos1["x"] - $pos2["x"];
		$Z = $pos1["z"] - $pos2["z"];
		$dXZ = sqrt(pow($X, 2) + pow($Z, 2));
		$Y = $pos1["y"] - $pos2["y"];
		$hAngle = rad2deg(atan2($Z, $X) - M_PI_2);
		$vAngle = rad2deg(-atan2($Y, $dXZ));

		return array("yaw" => $hAngle, "pitch" => $vAngle);
	}*/

	/**
	 * GETs an URL using cURL
	 *
	 * @param     $page
	 * @param int $timeout default 10
	 *
	 * @return bool|mixed
	 */
	public static function getURL($page, $timeout = 10, array $extraHeaders = []){
		if(Utils::$online === false){
			return false;
		}

		$ch = curl_init($page);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(["User-Agent: Mozilla/5.0 (Windows NT 6.1; WOW64; rv:12.0) Gecko/20100101 Firefox/12.0 PocketMine-MP"], $extraHeaders));
		curl_setopt($ch, CURLOPT_AUTOREFERER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int) $timeout);
		curl_setopt($ch, CURLOPT_TIMEOUT, (int) $timeout);
		$ret = curl_exec($ch);
		curl_close($ch);

		return $ret;
	}

	/**
	 * POSTs data to an URL
	 *
	 * @param              $page
	 * @param array|string $args
	 * @param int          $timeout
	 *
	 * @return bool|mixed
	 */
	public static function postURL($page, $args, $timeout = 10, array $extraHeaders = []){
		if(Utils::$online === false){
			return false;
		}

		$ch = curl_init($page);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $args);
		curl_setopt($ch, CURLOPT_AUTOREFERER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(["User-Agent: Mozilla/5.0 (Windows NT 6.1; WOW64; rv:12.0) Gecko/20100101 Firefox/12.0 PocketMine-MP"], $extraHeaders));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int) $timeout);
		curl_setopt($ch, CURLOPT_TIMEOUT, (int) $timeout);
		$ret = curl_exec($ch);
		curl_close($ch);

		return $ret;
	}

	public static function javaStringHash($string){
		$hash = 0;
		for($i = 0; $i < strlen($string); $i++){
			$ord = ord($string{$i});
			if($ord & 0x80){
				$ord -= 0x100;
			}
			$hash = 31 * $hash + $ord;
			while($hash > 0x7FFFFFFF){
				$hash -= 0x100000000;
			}
			while($hash < -0x80000000){
				$hash += 0x100000000;
			}
			$hash &= 0xFFFFFFFF;
		}
		return $hash;
	}

}

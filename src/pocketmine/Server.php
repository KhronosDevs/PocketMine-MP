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

namespace pocketmine;

use pocketmine\block\Block;
use pocketmine\command\CommandReader;
use pocketmine\command\CommandSender;
use pocketmine\command\ConsoleCommandSender;
use pocketmine\command\PluginIdentifiableCommand;
use pocketmine\command\SimpleCommandMap;
use pocketmine\entity\Attribute;
use pocketmine\entity\Effect;
use pocketmine\entity\Entity;
use pocketmine\event\HandlerList;
use pocketmine\event\level\LevelInitEvent;
use pocketmine\event\level\LevelLoadEvent;
use pocketmine\event\server\QueryRegenerateEvent;
use pocketmine\event\server\ServerCommandEvent;
use pocketmine\event\Timings;
use pocketmine\event\TimingsHandler;
use pocketmine\event\TranslationContainer;
use pocketmine\inventory\CraftingManager;
use pocketmine\inventory\InventoryType;
use pocketmine\inventory\Recipe;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\enchantment\EnchantmentLevelTable;
use pocketmine\item\Item;
use pocketmine\lang\BaseLang;
use pocketmine\level\format\anvil\Anvil;
use pocketmine\level\format\leveldb\LevelDB;
use pocketmine\level\format\LevelProviderManager;
use pocketmine\level\format\mcregion\McRegion;
use pocketmine\level\generator\biome\Biome;
use pocketmine\level\generator\Flat;
use pocketmine\level\generator\Generator;
use pocketmine\level\generator\hell\Nether;
use pocketmine\level\generator\normal\Normal;
use pocketmine\level\generator\normal\Normal2;
use pocketmine\level\generator\Void;
use pocketmine\level\Level;
use pocketmine\metadata\EntityMetadataStore;
use pocketmine\metadata\LevelMetadataStore;
use pocketmine\metadata\PlayerMetadataStore;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\LongTag;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\CompressBatchedTask;
use pocketmine\network\Network;
use pocketmine\network\protocol\BatchPacket;
use pocketmine\network\protocol\DataPacket;
use pocketmine\network\protocol\PlayerListPacket;
use pocketmine\network\query\QueryHandler;
use pocketmine\network\RakLibInterface;
use pocketmine\network\rcon\RCON;
use pocketmine\network\upnp\UPnP;
use pocketmine\permission\BanList;
use pocketmine\permission\DefaultPermissions;
use pocketmine\plugin\FolderPluginLoader;
use pocketmine\plugin\PharPluginLoader;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginLoadOrder;
use pocketmine\plugin\PluginManager;
use pocketmine\plugin\ScriptPluginLoader;
use pocketmine\scheduler\CallbackTask;
use pocketmine\scheduler\DServerTask;
use pocketmine\scheduler\FileWriteTask;
use pocketmine\scheduler\SendUsageTask;
use pocketmine\scheduler\ServerScheduler;
use pocketmine\snooze\TimeTrackingSleeperHandler;
use pocketmine\tile\Tile;
use pocketmine\utils\Binary;
use pocketmine\utils\Color;
use pocketmine\utils\Config;
use pocketmine\utils\Git;
use pocketmine\utils\LevelException;
use pocketmine\utils\MainLogger;
use pocketmine\utils\Terminal;
use pocketmine\utils\TextFormat;
use pocketmine\utils\Utils;
use pocketmine\utils\UUID;
use pocketmine\utils\VersionString;
use function array_key_exists;
use function array_shift;
use function array_sum;
use function asort;
use function base64_encode;
use function bccomp;
use function class_exists;
use function \pocketmine\cleanPath;
use function cli_set_process_title;
use function count;
use function define;
use function explode;
use function extension_loaded;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function floor;
use function function_exists;
use function gc_collect_cycles;
use function getmypid;
use function getopt;
use function implode;
use function ini_set;
use function is_array;
use function is_bool;
use function is_numeric;
use function is_string;
use function is_subclass_of;
use function json_decode;
use function max;
use function microtime;
use function min;
use function mkdir;
use function pcntl_signal;
use function pcntl_signal_dispatch;
use function random_bytes;
use function realpath;
use function register_shutdown_function;
use function rename;
use function round;
use function spl_object_hash;
use function str_repeat;
use function str_replace;
use function stripos;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function time;
use function time_sleep_until;
use function touch;
use function trim;
use function ucfirst;
use function zlib_encode;
use const DIRECTORY_SEPARATOR;
use const E_ERROR;
use const E_USER_ERROR;
use const E_USER_WARNING;
use const E_WARNING;
use const PHP_INT_MAX;
use const PHP_INT_SIZE;
use const SIGHUP;
use const SIGINT;
use const SIGTERM;
use const ZLIB_ENCODING_DEFLATE;

/**
 * The class that manages everything
 */
class Server{
	const BROADCAST_CHANNEL_ADMINISTRATIVE = "pocketmine.broadcast.admin";
	const BROADCAST_CHANNEL_USERS = "pocketmine.broadcast.user";

	const PLAYER_MSG_TYPE_MESSAGE = 0;
	const PLAYER_MSG_TYPE_TIP = 1;
	const PLAYER_MSG_TYPE_POPUP = 2;

	/** @var Server */
	private static $instance = null;

	/** @var \Threaded */
	private static $sleeper = null;

	/** @var BanList */
	private $banByName = null;

	/** @var BanList */
	private $banByIP = null;

	/** @var BanList */
	private $banByCID = \null;

	/** @var Config */
	private $operators = null;

	/** @var Config */
	private $whitelist = null;

	/** @var bool */
	private $isRunning = true;

	private $hasStopped = false;

	/** @var PluginManager */
	private $pluginManager = null;

	private $profilingTickRate = 20;

	/** @var ServerScheduler */
	private $scheduler = null;

	/**
	 * Counts the ticks since the server start
	 *
	 * @var int
	 */
	private $tickCounter;
	private $nextTick = 0;
	private $tickAverage = [20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20];
	private $useAverage = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
	private $maxTick = 20;
	private $maxUse = 0;

	private $sendUsageTicker = 0;

	private $dispatchSignals = false;

	/** @var \AttachableThreadedLogger */
	private $logger;

	/** @var MemoryManager */
	private $memoryManager;

	/** @var CommandReader */
	private $console = null;
	//private $consoleThreaded;

	/** @var SimpleCommandMap */
	private $commandMap = null;

	/** @var CraftingManager */
	private $craftingManager;

	/** @var ConsoleCommandSender */
	private $consoleSender;

	/** @var int */
	private $maxPlayers;

	/** @var bool */
	private $autoSave;

	/** @var RCON */
	private $rcon;

	/** @var EntityMetadataStore */
	private $entityMetadata;

	/** @var PlayerMetadataStore */
	private $playerMetadata;

	/** @var LevelMetadataStore */
	private $levelMetadata;

	/** @var Network */
	private $network;

	private $networkCompressionAsync = true;
	public $networkCompressionLevel = 7;

	private $autoTickRate = true;
	private $autoTickRateLimit = 20;
	private $alwaysTickPlayers = false;
	private $baseTickRate = 1;

	private $autoSaveTicker = 0;
	private $autoSaveTicks = 6000;

	/** @var BaseLang */
	private $baseLang;

	/** @var TimeTrackingSleeperHandler */
	private $tickSleeper = null;

	private $forceLanguage = false;

	private $serverID;

	private $autoloader;
	private $filePath;
	private $dataPath;
	private $pluginPath;

	private $uniquePlayers = [];

	/** @var QueryHandler */
	private $queryHandler;

	/** @var QueryRegenerateEvent */
	private $queryRegenerateTask = null;

	/** @var Config */
	private $properties;

	private $propertyCache = [];

	/** @var Config */
	private $config;
	/** @var Config */
	private $khronosConfig;

	/** @var Player[] */
	private $players = [];
	/** @var array<string, string> */
	private $playerIds = [];
	/** @var Player[] */
	private $playerList = [];

	private $identifiers = [];

	/** @var Level[] */
	private $levels = [];

	/** @var Level */
	private $levelDefault = null;

	private $aboutContent = "";

	/** Advanced Config */
	public $advancedConfig = null;

	public $weatherEnabled = true;
	public $foodEnabled = true;
	public $expEnabled = true;
	public $keepInventory = false;
	public $netherEnabled = false;
	public $netherName = "nether";
	public $netherLevel = null;
	public $weatherRandomDurationMin = 6000;
	public $weatherRandomDurationMax = 12000;
	public $lightningTime = 200;
	public $lightningFire = false;
	public $version;
	public $allowSnowGolem;
	public $allowIronGolem;
	public $autoClearInv = true;
	public $dserverConfig = [];
	public $dserverPlayers = 0;
	public $dserverAllPlayers = 0;
	public $redstoneEnabled = false;
	public $allowFrequencyPulse = true;
	public $anvilEnabled = false;
	public $pulseFrequency = 20;
	public $playerMsgType = self::PLAYER_MSG_TYPE_MESSAGE;
	public $playerLoginMsg = "";
	public $playerLogoutMsg = "";
	public $antiFly = false;
	public $asyncChunkRequest = true;
	public $recipesFromJson = false;
	public $creativeItemsFromJson = false;
	public $checkMovement = false;
	public $keepExperience = false;
	public $limitedCreative = true;
	public $chunkRadius = -1;
	public $destroyBlockParticle = true;
	public $allowSplashPotion = true;
	public $fireSpread = false;
	public $advancedCommandSelector = false;
	public $enchantingTableEnabled = true;
	public $countBookshelf = false;
	public $allowInventoryCheats = false;

	/**
	 * @param string $filePath
	 * @param string $dataPath
	 * @param string $pluginPath
	 * @param string $defaultLang
	 */
	public function __construct(\ClassLoader $autoloader, \ThreadedLogger $logger, $filePath, $dataPath, $pluginPath, $defaultLang = "unknown"){
		self::$instance = $this;
		self::$sleeper = new \Threaded();

		$this->autoloader = $autoloader;
		$this->logger = $logger;
		$this->filePath = $filePath;

		$this->dataPath = realpath($dataPath) . DIRECTORY_SEPARATOR;
		$this->pluginPath = realpath($pluginPath) . DIRECTORY_SEPARATOR;

		$this->console = new CommandReader($logger);
		$this->version = new VersionString($this->getPocketMineVersion());
	}

	public function createDefaultFolders(string $dataPath, string $pluginPath){
		if(!file_exists($dataPath . "worlds/")){
			mkdir($dataPath . "worlds/", 0777);
		}

		if(!file_exists($dataPath . "players/")){
			mkdir($dataPath . "players/", 0777);
		}

		if(!file_exists($pluginPath)){
			mkdir($pluginPath, 0777);
		}

		if(!file_exists($dataPath . "crashdumps/")){
			mkdir($dataPath . "crashdumps/", 0777);
		}
	}

	public function createDefaultConfigs(){
		$this->logger->info('Loading properties and configuration...');

		if(!file_exists($this->dataPath . "pocketmine.yml")){
			@file_put_contents($this->dataPath . "pocketmine.yml", file_get_contents($this->filePath . "src/pocketmine/resources/pocketmine.yml"));
		}

		$this->config = new Config($this->dataPath . "pocketmine.yml", Config::YAML, []);

		$this->khronosConfig = new Config($this->dataPath . 'khronos.yml', Config::YAML, [
			'pvp-mode' => false,
			'drop-items-on-death' => true
		]);

		if (!$this->khronosConfig->exists('pvp-mode')) {
			$this->khronosConfig->set('pvp-mode', false);
		}

		if (!$this->khronosConfig->exists('drop-items-on-death')) {
			$this->khronosConfig->set('drop-items-on-death', true);
		}

		$this->khronosConfig->save();

		$this->properties = new Config($this->dataPath . "server.properties", Config::PROPERTIES, [
			"motd" => "Minecraft: PE Server",
			"server-port" => 19132,
			"white-list" => false,
			"announce-player-achievements" => true,
			"spawn-protection" => 16,
			"max-players" => 20,
			"allow-flight" => false,
			"spawn-animals" => true,
			"spawn-mobs" => true,
			"gamemode" => 0,
			"force-gamemode" => false,
			"hardcore" => false,
			"pvp" => true,
			"difficulty" => 1,
			"generator-settings" => "",
			"level-name" => "world",
			"level-seed" => "",
			"level-type" => "DEFAULT",
			"enable-query" => true,
			"enable-rcon" => false,
			"rcon.password" => substr(base64_encode(random_bytes(20)), 3, 10),
			"auto-save" => true,
			"online-mode" => false,
		]);
	}

	public function setupGenisysConfig(string $defaultLang){
		$nowLang = $this->getProperty("settings.language", "eng");

		if($defaultLang != "unknown" && $nowLang != $defaultLang){
			@file_put_contents($this->dataPath . "pocketmine.yml", str_replace('language: "' . $nowLang . '"', 'language: "' . $defaultLang . '"', file_get_contents($this->dataPath . "pocketmine.yml")));
			$this->config->reload();
			unset($this->propertyCache["settings.language"]);
		}

		$lang = $this->getProperty("settings.language", BaseLang::FALLBACK_LANGUAGE);
		if(file_exists($this->filePath . "src/pocketmine/resources/genisys_$lang.yml")){
			$content = file_get_contents($file = $this->filePath . "src/pocketmine/resources/genisys_$lang.yml");
		} else {
			$content = file_get_contents($file = $this->filePath . "src/pocketmine/resources/genisys_eng.yml");
		}

		if(!file_exists($this->dataPath . "genisys.yml")){
			@file_put_contents($this->dataPath . "genisys.yml", $content);
		}

		$internelConfig = new Config($file, Config::YAML, []);
		$this->advancedConfig = new Config($this->dataPath . "genisys.yml", Config::YAML, []);

		$cfgVer = $this->getAdvancedProperty("config.version", 0, $internelConfig);
		$advVer = $this->getAdvancedProperty("config.version", 0);

		if($cfgVer > $advVer){
			$this->logger->notice("Your genisys.yml needs update");
			$this->logger->notice("Current Version: $advVer   Latest Version: $cfgVer");
		}

		$this->logger->info('Genisys configurations set.');
	}

	public function setupLanguage(){
		$this->forceLanguage = $this->getProperty("settings.force-language", false);
		$this->baseLang = new BaseLang($this->getProperty("settings.language", BaseLang::FALLBACK_LANGUAGE));

		$this->logger->info($this->getLanguage()->translateString("language.selected", [$this->getLanguage()->getName(), $this->getLanguage()->getLang()]));
	}

	public function setupAsyncWorkers(){
		if(($poolSize = $this->getProperty("settings.async-workers", "auto")) === "auto"){
			$poolSize = ServerScheduler::$WORKERS;
			$processors = Utils::getCoreCount() - 2;

			if($processors > 0){
				$poolSize = max(1, $processors);
			}
		}

		ServerScheduler::$WORKERS = $poolSize;
	}

	public function setupPocketmineProperties(){
		if($this->getProperty("network.batch-threshold", 256) >= 0){
			Network::$BATCH_THRESHOLD = (int) $this->getProperty("network.batch-threshold", 256);
		}else{
			Network::$BATCH_THRESHOLD = -1;
		}
		$this->networkCompressionLevel = $this->getProperty("network.compression-level", 7);
		$this->networkCompressionAsync = $this->getProperty("network.async-compression", true);

		$this->autoTickRate = (bool) $this->getProperty("level-settings.auto-tick-rate", true);
		$this->autoTickRateLimit = (int) $this->getProperty("level-settings.auto-tick-rate-limit", 20);
		$this->alwaysTickPlayers = (int) $this->getProperty("level-settings.always-tick-players", false);
		$this->baseTickRate = (int) $this->getProperty("level-settings.base-tick-rate", 1);

		$this->maxPlayers = $this->getConfigInt("max-players", 20);
		$this->setAutoSave($this->getConfigBoolean("auto-save", true));

		if($this->getConfigBoolean("hardcore", false) === true && $this->getDifficulty() < 3){
			$this->setConfigInt("difficulty", 3);
		}

		if($this->getConfigBoolean("enable-rcon", false) === true){
			$this->rcon = new RCON($this, $this->getConfigString("rcon.password", ""), $this->getConfigInt("rcon.port", $this->getPort()), ($ip = $this->getIp()) != "" ? $ip : "0.0.0.0", $this->getConfigInt("rcon.threads", 1), $this->getConfigInt("rcon.clients-per-thread", 50));
		}

		$this->entityMetadata = new EntityMetadataStore();
		$this->playerMetadata = new PlayerMetadataStore();
		$this->levelMetadata = new LevelMetadataStore();

		$this->scheduler = new ServerScheduler();

		$this->operators = new Config($this->dataPath . "ops.txt", Config::ENUM);
		$this->whitelist = new Config($this->dataPath . "white-list.txt", Config::ENUM);

		$this->profilingTickRate = (float) $this->getProperty("settings.profile-report-trigger", 20);

		$this->memoryManager = new MemoryManager($this);
	}

	public function setupBanLists(){
		if(file_exists($this->dataPath . "banned.txt") && !file_exists($this->dataPath . "banned-players.txt")){
			@rename($this->dataPath . "banned.txt", $this->dataPath . "banned-players.txt");
		}

		@touch($this->dataPath . "banned-players.txt");

		$this->banByName = new BanList($this->dataPath . "banned-players.txt");
		$this->banByName->load();

		@touch($this->dataPath . "banned-ips.txt");

		$this->banByIP = new BanList($this->dataPath . "banned-ips.txt");
		$this->banByIP->load();

		@touch($this->dataPath . "banned-cids.txt");

		$this->banByCID = new BanList($this->dataPath . "banned-cids.txt");
		$this->banByCID->load();
	}

	public function setupDebugMode(){
		define("pocketmine\\DEBUG", (int) $this->getProperty("debug.level", 1));

		if($this->logger instanceof MainLogger){
			$this->logger->setLogDebug(\pocketmine\DEBUG > 1);
		}

		if(\pocketmine\DEBUG >= 0){
			@cli_set_process_title($this->getName() . " " . $this->getPocketMineVersion());
		}
	}

	public function setupNetwork(){
		$this->logger->info($this->getLanguage()->translateString("pocketmine.server.networkStart", [$this->getIp() === "" ? "*" : $this->getIp(), $this->getPort()]));
		$this->serverID = Utils::getMachineUniqueId($this->getIp() . $this->getPort());

		$this->getLogger()->debug("Server unique id: " . $this->getServerUniqueId());
		$this->getLogger()->debug("Machine unique id: " . Utils::getMachineUniqueId());

		$this->network = new Network($this);
		$this->network->setName($this->getMotd());

		$this->queryRegenerateTask = new QueryRegenerateEvent($this, 5);

		$this->network->registerInterface(new RakLibInterface($this));
	}

	public function setupMinecraftRelatedStuff(){
		$this->consoleSender = new ConsoleCommandSender();
		$this->commandMap = new SimpleCommandMap($this);

		Entity::init();
		Tile::init();
		InventoryType::init();
		Block::init();
		Enchantment::init();
		Item::init($this->creativeItemsFromJson);
		Biome::init();
		Effect::init();
		Attribute::init();
		EnchantmentLevelTable::init();
		Color::init();
		$this->craftingManager = new CraftingManager($this->recipesFromJson);
	}

	public function setupPlugins(){
		$this->pluginManager = new PluginManager($this, $this->commandMap);
		$this->pluginManager->subscribeToPermission(Server::BROADCAST_CHANNEL_ADMINISTRATIVE, $this->consoleSender);
		$this->pluginManager->setUseTimings($this->getProperty("settings.enable-profiling", false));
		$this->pluginManager->registerInterface(PharPluginLoader::class);
		$this->pluginManager->registerInterface(FolderPluginLoader::class);
		$this->pluginManager->registerInterface(ScriptPluginLoader::class);

		$this->pluginManager->loadPlugins($this->pluginPath);

		$this->enablePlugins(PluginLoadOrder::STARTUP);
	}

	public function setupShutdownHandler(){
		register_shutdown_function([$this, "crashDump"]);
	}

	public function setupWorlds(){
		LevelProviderManager::addProvider($this, Anvil::class);
		LevelProviderManager::addProvider($this, McRegion::class);
		if(extension_loaded("leveldb")){
			$this->logger->debug($this->getLanguage()->translateString("pocketmine.debug.enable"));
			LevelProviderManager::addProvider($this, LevelDB::class);
		}

		Generator::addGenerator(Flat::class, "flat");
		Generator::addGenerator(Normal::class, "normal");
		Generator::addGenerator(Normal::class, "default");
		Generator::addGenerator(Nether::class, "hell");
		Generator::addGenerator(Nether::class, "nether");
		Generator::addGenerator(Void::class, "void");
		Generator::addGenerator(Normal2::class, "normal2");

		foreach((array) $this->getProperty("worlds", []) as $name => $worldSetting){
			if($this->loadLevel($name) === false){
				$seed = $this->getProperty("worlds.$name.seed", time());
				$options = explode(":", $this->getProperty("worlds.$name.generator", Generator::getGenerator("default")));
				$generator = Generator::getGenerator(array_shift($options));
				if(count($options) > 0){
					$options = [
						"preset" => implode(":", $options),
					];
				}else{
					$options = [];
				}

				$this->generateLevel($name, $seed, $generator, $options);
			}
		}

		if($this->getDefaultLevel() === null){
			$default = $this->getConfigString("level-name", "world");
			if(trim($default) == ""){
				$this->getLogger()->warning("level-name cannot be null, using default");
				$default = "world";
				$this->setConfigString("level-name", "world");
			}
			if($this->loadLevel($default) === false){
				$seed = getopt("", ["level-seed::"])["level-seed"] ?? $this->properties->get("level-seed", time());
				if(!is_numeric($seed) || bccomp($seed, "9223372036854775807") > 0){
					$seed = Utils::javaStringHash($seed);
				}elseif(PHP_INT_SIZE === 8){
					$seed = (int) $seed;
				}
				$this->generateLevel($default, $seed === 0 ? time() : $seed);
			}

			$this->setDefaultLevel($this->getLevelByName($default));
		}

		$this->properties->save();

		if(!($this->getDefaultLevel() instanceof Level)){
			$this->getLogger()->emergency($this->getLanguage()->translateString("pocketmine.level.defaultError"));
			$this->forceShutdown();
			return;
		}

		if($this->getProperty("ticks-per.autosave", 6000) > 0){
			$this->autoSaveTicks = (int) $this->getProperty("ticks-per.autosave", 6000);
		}

		$this->enablePlugins(PluginLoadOrder::POSTWORLD);
	}

	public function setupDServer(){
		if ($this->dserverConfig["enable"] && ($this->getAdvancedProperty("dserver.server-list", "") != "")) {
			$this->scheduler->scheduleRepeatingTask(new CallbackTask([$this,"updateDServerInfo"]), $this->dserverConfig["timer"]);
		}
	}

	public function getName() : string{
		return "Khronos";
	}

	/**
	 * @return bool
	 */
	public function isRunning(){
		return $this->isRunning === true;
	}

	public function getQueryHandler() : QueryHandler {
		return $this->queryHandler;
	}

	/**
	 * @return string
	 * Returns a formatted string of how long the server has been running for
	 */
	public function getUptime(){
		$time = microtime(true) - \pocketmine\START_TIME;

		$seconds = floor($time % 60);
		$minutes = null;
		$hours = null;
		$days = null;

		if($time >= 60){
			$minutes = floor(($time % 3600) / 60);
			if($time >= 3600){
				$hours = floor(($time % (3600 * 24)) / 3600);
				if($time >= 3600 * 24){
					$days = floor($time / (3600 * 24));
				}
			}
		}

		$uptime = ($minutes !== null ?
				($hours !== null ?
					($days !== null ?
						"$days " . $this->getLanguage()->translateString("%pocketmine.command.status.days") . " "
						: "") . "$hours " . $this->getLanguage()->translateString("%pocketmine.command.status.hours") . " "
					: "") . "$minutes " . $this->getLanguage()->translateString("%pocketmine.command.status.minutes") . " "
				: "") . "$seconds " . $this->getLanguage()->translateString("%pocketmine.command.status.seconds");
		return $uptime;
	}

	public function getKhronosVersion() : string {
		return \pocketmine\KHRONOS_VERSION;
	}

	/**
	 * @return string
	 */
	public function getPocketMineVersion(){
		return Git::getRepositoryStatePretty($this->dataPath);
	}

	public function getFormattedVersion($prefix = ""){
		$version = Git::getRepositoryStatePretty($this->dataPath);

		return ($version !== str_repeat("00", 20) ? $prefix . $version : $version);
	}

	/**
	 * @return string
	 */
	public function getCodename(){
		return \pocketmine\CODENAME;
	}

	/**
	 * @return string
	 */
	public function getVersion(){
		return \pocketmine\MINECRAFT_VERSION;
	}

	/**
	 * @return string
	 */
	public function getApiVersion(){
		return \pocketmine\API_VERSION;
	}

	/**
	 * @return string
	 */
	public function getiTXApiVersion(){
		return \pocketmine\GENISYS_API_VERSION;
	}

	/**
	 * @return string
	 */
	public function getGeniApiVersion(){
		return \pocketmine\GENISYS_API_VERSION;
	}

	/**
	 * @return string
	 */
	public function getFilePath(){
		return $this->filePath;
	}

	/**
	 * @return string
	 */
	public function getDataPath(){
		return $this->dataPath;
	}

	/**
	 * @return string
	 */
	public function getPluginPath(){
		return $this->pluginPath;
	}

	/**
	 * @return int
	 */
	public function getMaxPlayers(){
		return $this->maxPlayers;
	}

	/**
	 * @return int
	 */
	public function getPort(){
		return $this->getConfigInt("server-port", 19132);
	}

	/**
	 * @return int
	 */
	public function getViewDistance(){
		return max(56, $this->getProperty("chunk-sending.max-chunks", 256));
	}

	/**
	 * @return string
	 */
	public function getIp(){
		return $this->getConfigString("server-ip", "0.0.0.0");
	}

	public function getServerUniqueId(){
		return $this->serverID;
	}

	/**
	 * @return bool
	 */
	public function getAutoSave(){
		return $this->autoSave;
	}

	/**
	 * @param bool $value
	 */
	public function setAutoSave($value){
		$this->autoSave = (bool) $value;
		foreach($this->getLevels() as $level){
			$level->setAutoSave($this->autoSave);
		}
	}

	/**
	 * @return string
	 */
	public function getLevelType(){
		return $this->getConfigString("level-type", "DEFAULT");
	}

	/**
	 * @return bool
	 */
	public function getGenerateStructures(){
		return $this->getConfigBoolean("generate-structures", true);
	}

	/**
	 * @return int
	 */
	public function getGamemode(){
		return $this->getConfigInt("gamemode", 0) & 0b11;
	}

	/**
	 * @return bool
	 */
	public function getForceGamemode(){
		return $this->getConfigBoolean("force-gamemode", false);
	}

	public function getKhronosConfig(): Config {
		return $this->khronosConfig;
	}

	/**
	 * Returns the gamemode text name
	 *
	 * @param int $mode
	 *
	 * @return string
	 */
	public static function getGamemodeString($mode){
		switch((int) $mode){
			case Player::SURVIVAL:
				return "%gameMode.survival";
			case Player::CREATIVE:
				return "%gameMode.creative";
			case Player::ADVENTURE:
				return "%gameMode.adventure";
			case Player::SPECTATOR:
				return "%gameMode.spectator";
		}

		return "UNKNOWN";
	}

	/**
	 * Parses a string and returns a gamemode integer, -1 if not found
	 *
	 * @param string $str
	 *
	 * @return int
	 */
	public static function getGamemodeFromString($str){
		switch(strtolower(trim($str))){
			case (string) Player::SURVIVAL:
			case "survival":
			case "s":
				return Player::SURVIVAL;

			case (string) Player::CREATIVE:
			case "creative":
			case "c":
				return Player::CREATIVE;

			case (string) Player::ADVENTURE:
			case "adventure":
			case "a":
				return Player::ADVENTURE;

			case (string) Player::SPECTATOR:
			case "spectator":
			case "view":
			case "v":
				return Player::SPECTATOR;
		}
		return -1;
	}

	/**
	 * @param string $str
	 *
	 * @return int
	 */
	public static function getDifficultyFromString($str){
		switch(strtolower(trim($str))){
			case "0":
			case "peaceful":
			case "p":
				return 0;

			case "1":
			case "easy":
			case "e":
				return 1;

			case "2":
			case "normal":
			case "n":
				return 2;

			case "3":
			case "hard":
			case "h":
				return 3;
		}
		return -1;
	}

	/**
	 * @return int
	 */
	public function getDifficulty(){
		return $this->getConfigInt("difficulty", 1);
	}

	/**
	 * @return bool
	 */
	public function hasWhitelist(){
		return $this->getConfigBoolean("white-list", false);
	}

	/**
	 * @return int
	 */
	public function getSpawnRadius(){
		return $this->getConfigInt("spawn-protection", 16);
	}

	/**
	 * @return bool
	 */
	public function getAllowFlight(){
		return $this->getConfigBoolean("allow-flight", false);
	}

	/**
	 * @return bool
	 */
	public function isHardcore(){
		return $this->getConfigBoolean("hardcore", false);
	}

	/**
	 * @return int
	 */
	public function getDefaultGamemode(){
		return $this->getConfigInt("gamemode", 0) & 0b11;
	}

	/**
	 * @return string
	 */
	public function getMotd(){
		return $this->getConfigString("motd", "Minecraft: PE Server");
	}

	/**
	 * @return \ClassLoader
	 */
	public function getLoader(){
		return $this->autoloader;
	}

	/**
	 * @return MainLogger
	 */
	public function getLogger(){
		return $this->logger;
	}

	/**
	 * @return EntityMetadataStore
	 */
	public function getEntityMetadata(){
		return $this->entityMetadata;
	}

	/**
	 * @return PlayerMetadataStore
	 */
	public function getPlayerMetadata(){
		return $this->playerMetadata;
	}

	/**
	 * @return LevelMetadataStore
	 */
	public function getLevelMetadata(){
		return $this->levelMetadata;
	}

	/**
	 * @return PluginManager
	 */
	public function getPluginManager(){
		return $this->pluginManager;
	}

	/**
	 * @return CraftingManager
	 */
	public function getCraftingManager(){
		return $this->craftingManager;
	}

	/**
	 * @return ServerScheduler
	 */
	public function getScheduler(){
		return $this->scheduler;
	}

	/**
	 * @return int
	 */
	public function getTick(){
		return $this->tickCounter;
	}

	/**
	 * Returns the last server TPS measure
	 *
	 * @return float
	 */
	public function getTicksPerSecond(){
		return round($this->maxTick, 2);
	}

	/**
	 * Returns the last server TPS average measure
	 *
	 * @return float
	 */
	public function getTicksPerSecondAverage(){
		return round(array_sum($this->tickAverage) / count($this->tickAverage), 2);
	}

	/**
	 * Returns the TPS usage/load in %
	 *
	 * @return float
	 */
	public function getTickUsage(){
		return round($this->maxUse * 100, 2);
	}

	/**
	 * Returns the TPS usage/load average in %
	 *
	 * @return float
	 */
	public function getTickUsageAverage(){
		return round((array_sum($this->useAverage) / count($this->useAverage)) * 100, 2);
	}

	/**
	 * @return SimpleCommandMap
	 */
	public function getCommandMap(){
		return $this->commandMap;
	}

	/**
	 * @return Player[]
	 */
	public function getOnlinePlayers(){
		return $this->playerList;
	}

	public function addRecipe(Recipe $recipe){
		$this->craftingManager->registerRecipe($recipe);
	}

	public function shouldSavePlayerData() : bool{
		return (bool) $this->getProperty("player.save-player-data", true);
	}

	/**
	 * @param string $name
	 *
	 * @return OfflinePlayer|Player
	 */
	public function getOfflinePlayer($name){
		$name = strtolower($name);
		$result = $this->getPlayerExact($name);

		if($result === null){
			$result = new OfflinePlayer($this, $name);
		}

		return $result;
	}

	/**
	 * @param string $name
	 *
	 * @return CompoundTag
	 */
	public function getOfflinePlayerData($name){
		$name = strtolower($name);
		$path = $this->getDataPath() . "players/";
		if($this->shouldSavePlayerData()){
			if(file_exists($path . "$name.dat")){
				try{
					$nbt = new NBT(NBT::BIG_ENDIAN);
					$nbt->readCompressed(file_get_contents($path . "$name.dat"));

					return $nbt->getData();
				}catch(\Throwable $e){ //zlib decode error / corrupt data
					rename($path . "$name.dat", $path . "$name.dat.bak");
					$this->logger->notice($this->getLanguage()->translateString("pocketmine.data.playerCorrupted", [$name]));
				}
			}else{
				$this->logger->notice($this->getLanguage()->translateString("pocketmine.data.playerNotFound", [$name]));
			}
		}
		$spawn = $this->getDefaultLevel()->getSafeSpawn();
		$nbt = new CompoundTag("", [
			new LongTag("firstPlayed", floor(microtime(true) * 1000)),
			new LongTag("lastPlayed", floor(microtime(true) * 1000)),
			new ListTag("Pos", [
				new DoubleTag(0, $spawn->x),
				new DoubleTag(1, $spawn->y),
				new DoubleTag(2, $spawn->z)
			]),
			new StringTag("Level", $this->getDefaultLevel()->getName()),
			//new StringTag("SpawnLevel", $this->getDefaultLevel()->getName()),
			//new IntTag("SpawnX", (int) $spawn->x),
			//new IntTag("SpawnY", (int) $spawn->y),
			//new IntTag("SpawnZ", (int) $spawn->z),
			//new ByteTag("SpawnForced", 1), //TODO
			new ListTag("Inventory", []),
			new CompoundTag("Achievements", []),
			new IntTag("playerGameType", $this->getGamemode()),
			new ListTag("Motion", [
				new DoubleTag(0, 0.0),
				new DoubleTag(1, 0.0),
				new DoubleTag(2, 0.0)
			]),
			new ListTag("Rotation", [
				new FloatTag(0, 0.0),
				new FloatTag(1, 0.0)
			]),
			new FloatTag("FallDistance", 0.0),
			new ShortTag("Fire", 0),
			new ShortTag("Air", 300),
			new ByteTag("OnGround", 1),
			new ByteTag("Invulnerable", 0),
			new StringTag("NameTag", $name),
			new ShortTag("Health", 20),
			new ShortTag("MaxHealth", 20),
		]);
		$nbt->Pos->setTagType(NBT::TAG_Double);
		$nbt->Inventory->setTagType(NBT::TAG_Compound);
		$nbt->Motion->setTagType(NBT::TAG_Double);
		$nbt->Rotation->setTagType(NBT::TAG_Float);

		$this->saveOfflinePlayerData($name, $nbt);

		return $nbt;

	}

	/**
	 * @param string $name
	 * @param bool   $async
	 */
	public function saveOfflinePlayerData($name, CompoundTag $nbtTag, $async = false){
		if($this->shouldSavePlayerData()){
			$nbt = new NBT(NBT::BIG_ENDIAN);
			try{
				$nbt->setData($nbtTag);

				if($async){
					$this->getScheduler()->scheduleAsyncTask(new FileWriteTask($this->getDataPath() . "players/" . strtolower($name) . ".dat", $nbt->writeCompressed()));
				}else{
					file_put_contents($this->getDataPath() . "players/" . strtolower($name) . ".dat", $nbt->writeCompressed());
				}
			}catch(\Throwable $e){
				$this->logger->critical($this->getLanguage()->translateString("pocketmine.data.saveError", [$name, $e->getMessage()]));
				$this->logger->logException($e);
			}
		}
	}

	public function getPlayer(string $name) {
		$name = strtolower($name);
		$found = null;
		$delta = PHP_INT_MAX;

		foreach ($this->getOnlinePlayers() as $player) {
			$playerName = strtolower($player->getName());

			if (strpos($playerName, $name) !== 0) continue;

			$curDelta = strlen($playerName) - strlen($name);

			if ($curDelta < $delta) {
				$found = $player;
				$delta = $curDelta;
			}

			if ($curDelta === 0) break;
		}

		return $found;
	}

	/**
	 * @return Player|null
	 */
	public function getPlayerExact(string $name){
		return $this->playerList[$this->playerIds[strtolower($name)]] ?? null;
	}

	/**
	 * @param string $partialName
	 *
	 * @return Player[]
	 */
	public function matchPlayer($partialName){
		$partialName = strtolower($partialName);
		$matchedPlayers = [];
		foreach($this->getOnlinePlayers() as $player){
			if(strtolower($player->getName()) === $partialName){
				$matchedPlayers = [$player];
				break;
			}elseif(stripos($player->getName(), $partialName) !== false){
				$matchedPlayers[] = $player;
			}
		}

		return $matchedPlayers;
	}

	public function removePlayer(Player $player){
		if(isset($this->identifiers[$hash = spl_object_hash($player)])){
			$identifier = $this->identifiers[$hash];
			unset($this->players[$identifier]);
			unset($this->identifiers[$hash]);
			return;
		}

		foreach($this->players as $identifier => $p){
			if($player === $p){
				unset($this->players[$identifier]);
				unset($this->identifiers[spl_object_hash($player)]);
				break;
			}
		}
	}

	/**
	 * @return Level[]
	 */
	public function getLevels(){
		return $this->levels;
	}

	/**
	 * @return Level
	 */
	public function getDefaultLevel(){
		return $this->levelDefault;
	}

	/**
	 * Sets the default level to a different level
	 * This won't change the level-name property,
	 * it only affects the server on runtime
	 *
	 * @param Level $level
	 */
	public function setDefaultLevel($level){
		if($level === null || ($this->isLevelLoaded($level->getFolderName()) && $level !== $this->levelDefault)){
			$this->levelDefault = $level;
		}
	}

	/**
	 * @param string $name
	 *
	 * @return bool
	 */
	public function isLevelLoaded($name){
		return $this->getLevelByName($name) instanceof Level;
	}

	/**
	 * @param int $levelId
	 *
	 * @return Level
	 */
	public function getLevel($levelId){
		if(isset($this->levels[$levelId])){
			return $this->levels[$levelId];
		}

		return null;
	}

	/**
	 * @param $name
	 *
	 * @return Level
	 */
	public function getLevelByName($name){
		foreach($this->getLevels() as $level){
			if($level->getFolderName() === $name){
				return $level;
			}
		}

		return null;
	}

	/**
	 * @param bool $forceUnload
	 *
	 * @return bool
	 */
	public function unloadLevel(Level $level, $forceUnload = false){
		if($level === $this->getDefaultLevel() && !$forceUnload){
			throw new \InvalidStateException("The default level cannot be unloaded while running, please switch levels.");
		}
		if($level->unload($forceUnload) === true){
			unset($this->levels[$level->getId()]);

			return true;
		}

		return false;
	}

	/**
	 * Loads a level from the data directory
	 *
	 * @param string $name
	 *
	 * @return bool
	 *
	 * @throws LevelException
	 */
	public function loadLevel($name){
		if(trim($name) === ""){
			throw new LevelException("Invalid empty level name");
		}
		if($this->isLevelLoaded($name)){
			return true;
		}elseif(!$this->isLevelGenerated($name)){
			$this->logger->notice($this->getLanguage()->translateString("pocketmine.level.notFound", [$name]));

			return false;
		}

		$path = $this->getDataPath() . "worlds/" . $name . "/";

		$provider = LevelProviderManager::getProvider($path);

		if($provider === null){
			$this->logger->error($this->getLanguage()->translateString("pocketmine.level.loadError", [$name, "Unknown provider"]));

			return false;
		}
		//$entities = new Config($path."entities.yml", Config::YAML);
		//if(file_exists($path . "tileEntities.yml")){
		//	@rename($path . "tileEntities.yml", $path . "tiles.yml");
		//}

		try{
			$level = new Level($this, $name, $path, $provider);
		}catch(\Throwable $e){

			$this->logger->error($this->getLanguage()->translateString("pocketmine.level.loadError", [$name, $e->getMessage()]));
			if($this->logger instanceof MainLogger){
				$this->logger->logException($e);
			}
			return false;
		}

		$this->levels[$level->getId()] = $level;

		$level->initLevel();

		$this->getPluginManager()->callEvent(new LevelLoadEvent($level));

		$level->setTickRate($this->baseTickRate);

		return true;
	}

	/**
	 * Generates a new level if it does not exists
	 *
	 * @param string $name
	 * @param int    $seed
	 * @param string $generator Class name that extends pocketmine\level\generator\Noise
	 * @param array  $options
	 *
	 * @return bool
	 */
	public function generateLevel($name, $seed = null, $generator = null, $options = []){
		if(trim($name) === "" || $this->isLevelGenerated($name)){
			return false;
		}

		$seed = $seed === null ? Binary::readInt(random_bytes(4)) : (int) $seed;

		if(!isset($options["preset"])){
			$options["preset"] = $this->getConfigString("generator-settings", "");
		}

		if(!($generator !== null && class_exists($generator, true) && is_subclass_of($generator, Generator::class))){
			$generator = Generator::getGenerator($this->getLevelType());
		}

		if(($provider = LevelProviderManager::getProviderByName($providerName = $this->getProperty("level-settings.default-format", "anvil"))) === null){
			$provider = LevelProviderManager::getProviderByName($providerName = "anvil");
		}

		try{
			$path = $this->getDataPath() . "worlds/" . $name . "/";
			$provider::generate($path, $name, $seed, $generator, $options);

			$level = new Level($this, $name, $path, $provider);
			$this->levels[$level->getId()] = $level;

			$level->initLevel();

			$level->setTickRate($this->baseTickRate);
		}catch(\Throwable $e){
			$this->logger->error($this->getLanguage()->translateString("pocketmine.level.generateError", [$name, $e->getMessage()]));
			if($this->logger instanceof MainLogger){
				$this->logger->logException($e);
			}
			return false;
		}

		$this->getPluginManager()->callEvent(new LevelInitEvent($level));

		$this->getPluginManager()->callEvent(new LevelLoadEvent($level));

		$this->getLogger()->notice($this->getLanguage()->translateString("pocketmine.level.backgroundGeneration", [$name]));

		$centerX = $level->getSpawnLocation()->getX() >> 4;
		$centerZ = $level->getSpawnLocation()->getZ() >> 4;

		$order = [];

		for($X = -3; $X <= 3; ++$X){
			for($Z = -3; $Z <= 3; ++$Z){
				$distance = $X ** 2 + $Z ** 2;
				$chunkX = $X + $centerX;
				$chunkZ = $Z + $centerZ;
				$index = Level::chunkHash($chunkX, $chunkZ);
				$order[$index] = $distance;
			}
		}

		asort($order);

		foreach($order as $index => $distance){
			Level::getXZ($index, $chunkX, $chunkZ);
			$level->populateChunk($chunkX, $chunkZ, true);
		}

		return true;
	}

	/**
	 * @param string $name
	 *
	 * @return bool
	 */
	public function isLevelGenerated($name){
		if(trim($name) === ""){
			return false;
		}
		$path = $this->getDataPath() . "worlds/" . $name . "/";
		if(!($this->getLevelByName($name) instanceof Level)){

			if(LevelProviderManager::getProvider($path) === null){
				return false;
			}
			/*if(file_exists($path)){
				$level = new LevelImport($path);
				if($level->import() === false){ //Try importing a world
					return false;
				}
			}else{
				return false;
			}*/
		}

		return true;
	}

	/**
	 * @param string $variable
	 * @param string $defaultValue
	 *
	 * @return string
	 */
	public function getConfigString($variable, $defaultValue = ""){
		$v = getopt("", ["$variable::"]);
		if(isset($v[$variable])){
			return (string) $v[$variable];
		}

		return $this->properties->exists($variable) ? $this->properties->get($variable) : $defaultValue;
	}

	/**
	 * @param string $variable
	 * @param mixed  $defaultValue
	 *
	 * @return mixed
	 */
	public function getProperty($variable, $defaultValue = null){
		if(!array_key_exists($variable, $this->propertyCache)){
			$v = getopt("", ["$variable::"]);
			if(isset($v[$variable])){
				$this->propertyCache[$variable] = $v[$variable];
			}else{
				$this->propertyCache[$variable] = $this->config->getNested($variable);
			}
		}

		return $this->propertyCache[$variable] === null ? $defaultValue : $this->propertyCache[$variable];
	}

	/**
	 * @param string $variable
	 * @param string $value
	 */
	public function setConfigString($variable, $value){
		$this->properties->set($variable, $value);
	}

	/**
	 * @param string $variable
	 * @param int    $defaultValue
	 *
	 * @return int
	 */
	public function getConfigInt($variable, $defaultValue = 0){
		$v = getopt("", ["$variable::"]);
		if(isset($v[$variable])){
			return (int) $v[$variable];
		}

		return $this->properties->exists($variable) ? (int) $this->properties->get($variable) : (int) $defaultValue;
	}

	/**
	 * @param string $variable
	 * @param int    $value
	 */
	public function setConfigInt($variable, $value){
		$this->properties->set($variable, (int) $value);
	}

	/**
	 * @param string  $variable
	 * @param boolean $defaultValue
	 *
	 * @return boolean
	 */
	public function getConfigBoolean($variable, $defaultValue = false){
		$v = getopt("", ["$variable::"]);
		if(isset($v[$variable])){
			$value = $v[$variable];
		}else{
			$value = $this->properties->exists($variable) ? $this->properties->get($variable) : $defaultValue;
		}

		if(is_bool($value)){
			return $value;
		}
		switch(strtolower($value)){
			case "on":
			case "true":
			case "1":
			case "yes":
				return true;
		}

		return false;
	}

	/**
	 * @param string $variable
	 * @param bool   $value
	 */
	public function setConfigBool($variable, $value){
		$this->properties->set($variable, $value == true ? "1" : "0");
	}

	/**
	 * @param string $name
	 *
	 * @return PluginIdentifiableCommand
	 */
	public function getPluginCommand($name){
		if(($command = $this->commandMap->getCommand($name)) instanceof PluginIdentifiableCommand){
			return $command;
		}else{
			return null;
		}
	}

	/**
	 * @return BanList
	 */
	public function getNameBans(){
		return $this->banByName;
	}

	/**
	 * @return BanList
	 */
	public function getIPBans(){
		return $this->banByIP;
	}

	public function getCIDBans(){
		return $this->banByCID;
	}

	/**
	 * @param string $name
	 */
	public function addOp($name){
		$this->operators->set(strtolower($name), true);

		if(($player = $this->getPlayerExact($name)) !== null){
			$player->recalculatePermissions();
		}
		$this->operators->save(true);
	}

	/**
	 * @param string $name
	 */
	public function removeOp($name){
		foreach($this->operators->getAll() as $opName => $dummyValue){
			if(strtolower($name) === strtolower($opName)){
				$this->operators->remove($opName);
			}
		}

		if(($player = $this->getPlayerExact($name)) !== null){
			$player->recalculatePermissions();
		}
		$this->operators->save();
	}

	/**
	 * @param string $name
	 */
	public function addWhitelist($name){
		$this->whitelist->set(strtolower($name), true);
		$this->whitelist->save(true);
	}

	/**
	 * @param string $name
	 */
	public function removeWhitelist($name){
		$this->whitelist->remove(strtolower($name));
		$this->whitelist->save();
	}

	/**
	 * @param string $name
	 *
	 * @return bool
	 */
	public function isWhitelisted($name){
		return !$this->hasWhitelist() || $this->whitelist->exists($name, true);
	}

	/**
	 * @param string $name
	 *
	 * @return bool
	 */
	public function isOp($name){
		return $this->operators->exists($name, true);
	}

	/**
	 * @return Config
	 */
	public function getWhitelisted(){
		return $this->whitelist;
	}

	/**
	 * @return Config
	 */
	public function getOps(){
		return $this->operators;
	}

	public function reloadWhitelist(){
		$this->whitelist->reload();
	}

	/**
	 * @return string[]
	 */
	public function getCommandAliases(){
		$section = $this->getProperty("aliases");
		$result = [];
		if(is_array($section)){
			foreach($section as $key => $value){
				$commands = [];
				if(is_array($value)){
					$commands = $value;
				}else{
					$commands[] = $value;
				}

				$result[$key] = $commands;
			}
		}

		return $result;
	}

	public function getCrashPath(){
		return $this->dataPath . "crashdumps/";
	}

	public static function getInstance() : Server{
		return self::$instance;
	}

	public static function microSleep(int $microseconds){
		Server::$sleeper->synchronized(function(int $ms){
			Server::$sleeper->wait($ms);
		}, $microseconds);
	}

	public function about(){
		$this->getLogger()->info('

  	§bKhronosAPI§f is a fork of §bPocketMine-MP§f, mantained by §bKhronos Devs§f
		
	    §bDiscord: §fhttps://discord.gg/gR24R3wR
	§bGithub: §fhttps://github.com/KhronosDevs/PocketMine-MP

		§bMain maintainer\'s discord: §fxchillz.

		§bDevice information:
		§7  - §bOS: §f' . ucfirst(Utils::getOS()) . '
		§7  - §bKhronos version: §f' . $this->getKhronosVersion() . '
		');
	}

	public function loadAdvancedConfig(){
		$this->playerMsgType = $this->getAdvancedProperty("server.player-msg-type", self::PLAYER_MSG_TYPE_MESSAGE);
		$this->playerLoginMsg = $this->getAdvancedProperty("server.login-msg", "§3@player joined the game");
		$this->playerLogoutMsg = $this->getAdvancedProperty("server.logout-msg", "§3@player left the game");
		$this->weatherEnabled = $this->getAdvancedProperty("level.weather", true);
		$this->foodEnabled = $this->getAdvancedProperty("player.hunger", true);
		$this->expEnabled = $this->getAdvancedProperty("player.experience", true);
		$this->keepInventory = $this->getAdvancedProperty("player.keep-inventory", false);
		$this->keepExperience = $this->getAdvancedProperty("player.keep-experience", false);
		$this->netherEnabled = $this->getAdvancedProperty("nether.allow-nether", false);
		$this->netherName = $this->getAdvancedProperty("nether.level-name", "nether");
		$this->weatherRandomDurationMin = $this->getAdvancedProperty("level.weather-random-duration-min", 6000);
		$this->weatherRandomDurationMax = $this->getAdvancedProperty("level.weather-random-duration-max", 12000);
		$this->lightningTime = $this->getAdvancedProperty("level.lightning-time", 200);
		$this->lightningFire = $this->getAdvancedProperty("level.lightning-fire", false);
		$this->allowSnowGolem = $this->getAdvancedProperty("server.allow-snow-golem", false);
		$this->allowIronGolem = $this->getAdvancedProperty("server.allow-iron-golem", false);
		$this->autoClearInv = $this->getAdvancedProperty("player.auto-clear-inventory", true);
		$this->dserverConfig = [
			"enable" => $this->getAdvancedProperty("dserver.enable", false),
			"queryAutoUpdate" => $this->getAdvancedProperty("dserver.query-auto-update", false),
			"queryTickUpdate" => $this->getAdvancedProperty("dserver.query-tick-update", true),
			"motdMaxPlayers" => $this->getAdvancedProperty("dserver.motd-max-players", 0),
			"queryMaxPlayers" => $this->getAdvancedProperty("dserver.query-max-players", 0),
			"motdAllPlayers" => $this->getAdvancedProperty("dserver.motd-all-players", false),
			"queryAllPlayers" => $this->getAdvancedProperty("dserver.query-all-players", false),
			"motdPlayers" => $this->getAdvancedProperty("dserver.motd-players", false),
			"queryPlayers" => $this->getAdvancedProperty("dserver.query-players", false),
			"timer" => $this->getAdvancedProperty("dserver.time", 40),
			"retryTimes" => $this->getAdvancedProperty("dserver.retry-times", 3),
			"serverList" => explode(";", $this->getAdvancedProperty("dserver.server-list", ""))
		];
		$this->redstoneEnabled = $this->getAdvancedProperty("redstone.enable", false);
		$this->allowFrequencyPulse = $this->getAdvancedProperty("redstone.allow-frequency-pulse", false);
		$this->pulseFrequency = $this->getAdvancedProperty("redstone.pulse-frequency", 20);
		$this->getLogger()->setWrite(!$this->getAdvancedProperty("server.disable-log", false));
		$this->antiFly = $this->getAdvancedProperty("server.anti-fly", true);
		$this->asyncChunkRequest = $this->getAdvancedProperty("server.async-chunk-request", true);
		$this->recipesFromJson = $this->getAdvancedProperty("server.recipes-from-json", false);
		$this->creativeItemsFromJson = $this->getAdvancedProperty("server.creative-items-from-json", false);
		$this->checkMovement = $this->getAdvancedProperty("server.check-movement", true);
		$this->limitedCreative = $this->getAdvancedProperty("server.limited-creative", true);
		$this->chunkRadius = $this->getAdvancedProperty("player.chunk-radius", -1);
		$this->destroyBlockParticle = $this->getAdvancedProperty("server.destroy-block-particle", true);
		$this->allowSplashPotion = $this->getAdvancedProperty("server.allow-splash-potion", true);
		$this->fireSpread = $this->getAdvancedProperty("level.fire-spread", false);
		$this->advancedCommandSelector = $this->getAdvancedProperty("server.advanced-command-selector", false);
		$this->anvilEnabled = $this->getAdvancedProperty("enchantment.enable-anvil", true);
		$this->enchantingTableEnabled = $this->getAdvancedProperty("enchantment.enable-enchanting-table", true);
		$this->countBookshelf = $this->getAdvancedProperty("enchantment.count-bookshelf", false);

		$this->allowInventoryCheats = $this->getAdvancedProperty("inventory.allow-cheats", false);
	}

	/**
	 * @return int
	 *
	 * Get DServer max players
	 */
	public function getDServerMaxPlayers(){
		return ($this->dserverAllPlayers + $this->getMaxPlayers());
	}

	/**
	 * @return int
	 *
	 * Get DServer all online player count
	 */
	public function getDServerOnlinePlayers(){
		return ($this->dserverPlayers + count($this->getOnlinePlayers()));
	}

	public function isDServerEnabled(){
		return $this->dserverConfig["enable"];
	}

	public function updateDServerInfo(){
		$this->scheduler->scheduleAsyncTask(new DServerTask($this->dserverConfig["serverList"], $this->dserverConfig["retryTimes"]));
	}

	public function getBuild(){
		return $this->version->getBuild();
	}

	public function getGameVersion(){
		return $this->version->getRelease();
	}

	/**
	 * @param string        $message
	 * @param Player[]|null $recipients
	 */
	public function broadcastMessage($message, $recipients = null) : int{
		if(!is_array($recipients)){
			return $this->broadcast($message, self::BROADCAST_CHANNEL_USERS);
		}

		/** @var Player[] $recipients */
		foreach($recipients as $recipient){
			$recipient->sendMessage($message);
		}

		return count($recipients);
	}

	/**
	 * @param Player[]|null $recipients
	 */
	public function broadcastTip(string $tip, $recipients = null) : int{
		if(!is_array($recipients)){
			/** @var Player[] $recipients */
			$recipients = [];

			foreach($this->pluginManager->getPermissionSubscriptions(self::BROADCAST_CHANNEL_USERS) as $permissible){
				if($permissible instanceof Player && $permissible->hasPermission(self::BROADCAST_CHANNEL_USERS)){
					$recipients[spl_object_hash($permissible)] = $permissible; // do not send messages directly, or some might be repeated
				}
			}
		}

		/** @var Player[] $recipients */
		foreach($recipients as $recipient){
			$recipient->sendTip($tip);
		}

		return count($recipients);
	}

	public function getTickSleeper() : TimeTrackingSleeperHandler {
		return $this->tickSleeper ?? ($this->tickSleeper = new TimeTrackingSleeperHandler(Timings::$serverInterrupts));
	}

	/**
	 * @param Player[]|null $recipients
	 */
	public function broadcastPopup(string $popup, $recipients = null) : int{
		if(!is_array($recipients)){
			/** @var Player[] $recipients */
			$recipients = [];

			foreach($this->pluginManager->getPermissionSubscriptions(self::BROADCAST_CHANNEL_USERS) as $permissible){
				if($permissible instanceof Player && $permissible->hasPermission(self::BROADCAST_CHANNEL_USERS)){
					$recipients[spl_object_hash($permissible)] = $permissible; // do not send messages directly, or some might be repeated
				}
			}
		}

		/** @var Player[] $recipients */
		foreach($recipients as $recipient){
			$recipient->sendPopup($popup);
		}

		return count($recipients);
	}

	/**
	 * @param string $message
	 */
	public function broadcast($message, string $permissions) : int{
		/** @var CommandSender[] $recipients */
		$recipients = [];
		foreach(explode(";", $permissions) as $permission){
			foreach($this->pluginManager->getPermissionSubscriptions($permission) as $permissible){
				if($permissible instanceof CommandSender && $permissible->hasPermission($permission)){
					$recipients[spl_object_hash($permissible)] = $permissible; // do not send messages directly, or some might be repeated
				}
			}
		}

		foreach($recipients as $recipient){
			$recipient->sendMessage($message);
		}

		return count($recipients);
	}

	/**
	 * Broadcasts a Minecraft packet to a list of players
	 *
	 * @param Player[] $players
	 */
	public static function broadcastPacket(array $players, DataPacket $packet){
		$packet->encode();
		$packet->isEncoded = true;
		if(Network::$BATCH_THRESHOLD >= 0 && strlen($packet->buffer) >= Network::$BATCH_THRESHOLD){
			Server::getInstance()->batchPackets($players, [$packet->buffer], false);
			return;
		}

		foreach($players as $player){
			$player->dataPacket($packet);
		}
		if(isset($packet->__encapsulatedPacket)){
			unset($packet->__encapsulatedPacket);
		}
	}

	/**
	 * Broadcasts a list of packets in a batch to a list of players
	 *
	 * @param Player[]            $players
	 * @param DataPacket[]|string $packets
	 * @param bool                $forceSync
	 */
	public function batchPackets(array $players, array $packets, $forceSync = false){
		Timings::$playerNetworkTimer->startTiming();
		$str = "";

		foreach($packets as $p){
			if($p instanceof DataPacket){
				if(!$p->isEncoded){
					$p->encode();
				}
				$str .= Binary::writeInt(strlen($p->buffer)) . $p->buffer;
			}else{
				$str .= Binary::writeInt(strlen($p)) . $p;
			}
		}

		$targets = [];
		foreach($players as $p){
			if($p->isConnected()){
				$targets[] = $this->identifiers[spl_object_hash($p)];
			}
		}

		if(!$forceSync && $this->networkCompressionAsync){
			$task = new CompressBatchedTask($str, $targets, $this->networkCompressionLevel);
			$this->getScheduler()->scheduleAsyncTask($task);
		}else{
			$this->broadcastPacketsCallback(zlib_encode($str, ZLIB_ENCODING_DEFLATE, $this->networkCompressionLevel), $targets);
		}

		Timings::$playerNetworkTimer->stopTiming();
	}

	public function broadcastPacketsCallback($data, array $identifiers){
		$pk = new BatchPacket();
		$pk->payload = $data;
		$pk->encode();
		$pk->isEncoded = true;

		foreach($identifiers as $i){
			if(isset($this->players[$i])){
				$this->players[$i]->dataPacket($pk);
			}
		}
	}

	public function enablePlugins(int $type){
		foreach($this->pluginManager->getPlugins() as $plugin){
			if(!$plugin->isEnabled() && $plugin->getDescription()->getOrder() === $type){
				$this->enablePlugin($plugin);
			}
		}

		if($type === PluginLoadOrder::POSTWORLD){
			$this->commandMap->registerServerAliases();
			DefaultPermissions::registerCorePermissions();
		}
	}

	public function enablePlugin(Plugin $plugin){
		$this->pluginManager->enablePlugin($plugin);
	}

	public function checkConsole(){
		Timings::$serverCommandTimer->startTiming();
		if(($line = $this->console->getLine()) !== null){
			$this->pluginManager->callEvent($ev = new ServerCommandEvent($this->consoleSender, $line));
			if(!$ev->isCancelled()){
				$this->dispatchCommand($ev->getSender(), $ev->getCommand());
			}
		}
		Timings::$serverCommandTimer->stopTiming();
	}

	/**
	 * Executes a command from a CommandSender
	 *
	 * @param string $commandLine
	 *
	 * @return bool
	 *
	 * @throws \Throwable
	 */
	public function dispatchCommand(CommandSender $sender, $commandLine){
		if($this->commandMap->dispatch($sender, $commandLine)){
			return true;
		}

		$sender->sendMessage(new TranslationContainer(TextFormat::RED . "%commands.generic.notFound"));

		return false;
	}

	public function reload(){
		$this->logger->info("Saving levels...");

		foreach($this->levels as $level){
			$level->save();
		}

		$this->pluginManager->disablePlugins();
		$this->pluginManager->clearPlugins();
		$this->commandMap->clearCommands();

		$this->logger->info("Reloading properties...");
		$this->properties->reload();
		$this->advancedConfig->reload();
		$this->loadAdvancedConfig();
		$this->maxPlayers = $this->getConfigInt("max-players", 20);

		if($this->getConfigBoolean("hardcore", false) === true && $this->getDifficulty() < 3){
			$this->setConfigInt("difficulty", 3);
		}

		$this->banByIP->load();
		$this->banByName->load();
		$this->banByCID->load();
		$this->reloadWhitelist();
		$this->operators->reload();

		$this->memoryManager->doObjectCleanup();

		foreach($this->getIPBans()->getEntries() as $entry){
			$this->getNetwork()->blockAddress($entry->getName(), -1);
		}

		$this->pluginManager->registerInterface(PharPluginLoader::class);
		$this->pluginManager->registerInterface(FolderPluginLoader::class);
		$this->pluginManager->registerInterface(ScriptPluginLoader::class);
		$this->pluginManager->loadPlugins($this->pluginPath);
		$this->enablePlugins(PluginLoadOrder::STARTUP);
		$this->enablePlugins(PluginLoadOrder::POSTWORLD);
		TimingsHandler::reload();
	}

	/**
	 * Shutdowns the server correctly
	 */
	public function shutdown(bool $restart = false, string $msg = ""){
		/*if($this->isRunning){
			$killer = new ServerKiller(90);
			$killer->start();
			$killer->kill();
		}*/
		$this->isRunning = false;
		if($msg != ""){
			$this->propertyCache["settings.shutdown-message"] = $msg;
		}
	}

	public function forceShutdown(){
		if($this->hasStopped){
			return;
		}

		try{
			if(!$this->isRunning()){
				$this->sendUsage(SendUsageTask::TYPE_CLOSE);
			}

			$this->hasStopped = true;

			$this->shutdown();
			if($this->rcon instanceof RCON){
				$this->rcon->stop();
			}

			if($this->getProperty("network.upnp-forwarding", false) === true){
				$this->logger->info("[UPnP] Removing port forward...");
				UPnP::RemovePortForward($this->getPort());
			}

			$this->getLogger()->debug("Disabling all plugins");
			$this->pluginManager->disablePlugins();

			foreach($this->players as $player){
				$player->close($player->getLeaveMessage(), $this->getProperty("settings.shutdown-message", "Server closed"));
			}

			$this->getLogger()->debug("Unloading all levels");
			foreach($this->getLevels() as $level){
				$this->unloadLevel($level, true);
			}

			$this->getLogger()->debug("Removing event handlers");
			HandlerList::unregisterAll();

			$this->getLogger()->debug("Stopping all tasks");
			$this->scheduler->cancelAllTasks();
			$this->scheduler->mainThreadHeartbeat(PHP_INT_MAX);

			$this->getLogger()->debug("Saving properties");
			$this->properties->save();

			$this->getLogger()->debug("Closing console");
			$this->console->shutdown();
			$this->console->notify();

			$this->getLogger()->debug("Stopping network interfaces");
			foreach($this->network->getInterfaces() as $interface){
				$interface->shutdown();
				$this->network->unregisterInterface($interface);
			}

			//$this->memoryManager->doObjectCleanup();

			gc_collect_cycles();
		}catch(\Throwable $e){
			$this->logger->logException($e);
			$this->logger->emergency("Crashed while crashing, killing process");
			@kill(getmypid());
		}

	}

	public function getQueryInformation(){
		return $this->queryRegenerateTask;
	}

	/**
	 * Starts the PocketMine-MP server and starts processing ticks and packets
	 */
	public function start(){
		if($this->getConfigBoolean("enable-query", true) === true){
			$this->queryHandler = new QueryHandler();
		}

		foreach($this->getIPBans()->getEntries() as $entry){
			$this->network->blockAddress($entry->getName(), -1);
		}

		if($this->getProperty("settings.send-usage", true)){
			$this->sendUsageTicker = 6000;
			$this->sendUsage(SendUsageTask::TYPE_OPEN);
		}

		if($this->getProperty("network.upnp-forwarding", false) == true){
			$this->logger->info("[UPnP] Trying to port forward...");
			UPnP::PortForward($this->getPort());
		}

		$this->tickCounter = 0;

		if(function_exists("pcntl_signal")){
			pcntl_signal(SIGTERM, [$this, "handleSignal"]);
			pcntl_signal(SIGINT, [$this, "handleSignal"]);
			pcntl_signal(SIGHUP, [$this, "handleSignal"]);
			$this->dispatchSignals = true;
		}

		$this->logger->info($this->getLanguage()->translateString("pocketmine.server.defaultGameMode", [self::getGamemodeString($this->getGamemode())]));

		$this->logger->info($this->getLanguage()->translateString("pocketmine.server.startFinished", [round(microtime(true) - \pocketmine\START_TIME, 3)]));

		$this->tickProcessor();
		$this->forceShutdown();

		gc_collect_cycles();
	}

	public function handleSignal($signo){
		if($signo === SIGTERM || $signo === SIGINT || $signo === SIGHUP){
			$this->shutdown();
		}
	}

	public function exceptionHandler(\Throwable $e, $trace = null){
		if($e === null){
			return;
		}

		global $lastError;

		if($trace === null){
			$trace = $e->getTrace();
		}

		$errstr = $e->getMessage();
		$errfile = $e->getFile();
		$errno = $e->getCode();
		$errline = $e->getLine();

		$type = ($errno === E_ERROR || $errno === E_USER_ERROR) ? \LogLevel::ERROR : (($errno === E_USER_WARNING || $errno === E_WARNING) ? \LogLevel::WARNING : \LogLevel::NOTICE);
		if(($pos = strpos($errstr, "\n")) !== false){
			$errstr = substr($errstr, 0, $pos);
		}

		$errfile = cleanPath($errfile);

		if($this->logger instanceof MainLogger){
			$this->logger->logException($e, $trace);
		}

		$lastError = [
			"type" => $type,
			"message" => $errstr,
			"fullFile" => $e->getFile(),
			"file" => $errfile,
			"line" => $errline,
			"trace" => @getTrace(1, $trace)
		];

		global $lastExceptionError, $lastError;
		$lastExceptionError = $lastError;
		$this->crashDump();
	}

	public function crashDump(){
		if($this->isRunning === false){
			return;
		}
		if($this->sendUsageTicker > 0){
			$this->sendUsage(SendUsageTask::TYPE_CLOSE);
		}
		$this->hasStopped = false;

		ini_set("error_reporting", '0');
		ini_set("memory_limit", '-1'); //Fix error dump not dumped on memory problems
		$this->logger->emergency($this->getLanguage()->translateString("pocketmine.crash.create"));
		try{
			$dump = new CrashDump($this);
		}catch(\Throwable $e){
			$this->logger->critical($this->getLanguage()->translateString("pocketmine.crash.error", [$e->getMessage()]));
			return;
		}

		$this->logger->emergency($this->getLanguage()->translateString("pocketmine.crash.submit", [$dump->getPath()]));

		if($this->getProperty("auto-report.enabled", true) !== false){
			$report = true;
			$plugin = $dump->getData()["plugin"];
			if(is_string($plugin)){
				$p = $this->pluginManager->getPlugin($plugin);
				if($p instanceof Plugin && !($p->getPluginLoader() instanceof PharPluginLoader)){
					$report = false;
				}
			}elseif(\Phar::running(true) == ""){
				$report = false;
			}
			if($dump->getData()["error"]["type"] === "E_PARSE" || $dump->getData()["error"]["type"] === "E_COMPILE_ERROR"){
				$report = false;
			}

			if($report){
				$reply = Utils::postURL("http://" . $this->getProperty("auto-report.host", "crash.pocketmine.net") . "/submit/api", [
					"report" => "yes",
					"name" => $this->getName() . " " . $this->getPocketMineVersion(),
					"email" => "crash@pocketmine.net",
					"reportPaste" => base64_encode($dump->getEncodedData())
				]);

				if(($data = json_decode($reply)) !== false && isset($data->crashId)){
					$reportId = $data->crashId;
					$reportUrl = $data->crashUrl;
					$this->logger->emergency($this->getLanguage()->translateString("pocketmine.crash.archive", [$reportUrl, $reportId]));
				}
			}
		}

		//$this->checkMemory();
		//$dump .= "Memory Usage Tracking: \r\n" . chunk_split(base64_encode(gzdeflate(implode(";", $this->memoryStats), 9))) . "\r\n";

		$this->forceShutdown();
		$this->isRunning = false;
		@kill(getmypid());
		exit(1);
	}

	public function __debugInfo(){
		return [];
	}

	private function tickProcessor(){
		$this->nextTick = microtime(true);
		while($this->isRunning){
			$this->tick();

			$this->getTickSleeper()->sleepUntil($this->nextTick);

			$next = $this->nextTick - 0.0001;
			if($next > microtime(true)){
				@time_sleep_until($next);
			}
		}
	}

	public function onPlayerLogin(Player $player){
		if($this->sendUsageTicker > 0){
			$this->uniquePlayers[$player->getRawUniqueId()] = $player->getRawUniqueId();
		}

		$this->sendFullPlayerListData($player);
		$player->dataPacket($this->craftingManager->getCraftingDataPacket());
	}

	public function addPlayer($identifier, Player $player){
		$this->players[$identifier] = $player;
		$this->identifiers[spl_object_hash($player)] = $identifier;
	}

	public function addOnlinePlayer(Player $player){
		$this->playerList[$player->getRawUniqueId()] = $player;
		$this->playerIds[strtolower($player->getName())] = $player->getRawUniqueId();

		$this->updatePlayerListData($player->getUniqueId(), $player->getId(), $player->getDisplayName(), $player->getSkinId(), $player->getSkinData());
	}

	public function removeOnlinePlayer(Player $player){
		if(isset($this->playerList[$player->getRawUniqueId()])){
			unset($this->playerList[$player->getRawUniqueId()]);
			unset($this->playerIds[strtolower($player->getName())]);

			$pk = new PlayerListPacket();
			$pk->type = PlayerListPacket::TYPE_REMOVE;
			$pk->entries[] = [$player->getUniqueId()];
			Server::broadcastPacket($this->playerList, $pk);
		}
	}

	public function updatePlayerListData(UUID $uuid, $entityId, $name, $skinId, $skinData, array $players = null){
		$pk = new PlayerListPacket();
		$pk->type = PlayerListPacket::TYPE_ADD;
		$pk->entries[] = [$uuid, $entityId, $name, $skinId, $skinData];
		Server::broadcastPacket($players === null ? $this->playerList : $players, $pk);
	}

	public function removePlayerListData(UUID $uuid, array $players = null){
		$pk = new PlayerListPacket();
		$pk->type = PlayerListPacket::TYPE_REMOVE;
		$pk->entries[] = [$uuid];
		Server::broadcastPacket($players === null ? $this->playerList : $players, $pk);
	}

	public function sendFullPlayerListData(Player $p){
		$pk = new PlayerListPacket();
		$pk->type = PlayerListPacket::TYPE_ADD;
		foreach($this->playerList as $player){
			if ($p === $player) continue;

			$pk->entries[] = [$player->getUniqueId(), $player->getId(), $player->getDisplayName(), $player->getSkinId(), $player->getSkinData()];
		}

		$p->dataPacket($pk);
	}

	private function checkTickUpdates($currentTick, $tickTime){
		foreach($this->players as $p){
			if(!$p->loggedIn && ($tickTime - $p->creationTime) >= 10){
				$p->close("", "Login timeout");
			}elseif($this->alwaysTickPlayers){
				$p->onUpdate($currentTick);
			}
		}

		//Do level ticks
		foreach($this->getLevels() as $level){
			if($level->getTickRate() > $this->baseTickRate && --$level->tickRateCounter > 0){
				continue;
			}
			try{
				$levelTime = microtime(true);
				$level->doTick($currentTick);
				$tickMs = (microtime(true) - $levelTime) * 1000;
				$level->tickRateTime = $tickMs;

				if($this->autoTickRate){
					if($tickMs < 50 && $level->getTickRate() > $this->baseTickRate){
						$level->setTickRate($r = $level->getTickRate() - 1);
						if($r > $this->baseTickRate){
							$level->tickRateCounter = $level->getTickRate();
						}
						$this->getLogger()->debug("Raising level \"" . $level->getName() . "\" tick rate to " . $level->getTickRate() . " ticks");
					}elseif($tickMs >= 50){
						if($level->getTickRate() === $this->baseTickRate){
							$level->setTickRate(max($this->baseTickRate + 1, min($this->autoTickRateLimit, floor($tickMs / 50))));
							$this->getLogger()->debug("Level \"" . $level->getName() . "\" took " . round($tickMs, 2) . "ms, setting tick rate to " . $level->getTickRate() . " ticks");
						}elseif(($tickMs / $level->getTickRate()) >= 50 && $level->getTickRate() < $this->autoTickRateLimit){
							$level->setTickRate($level->getTickRate() + 1);
							$this->getLogger()->debug("Level \"" . $level->getName() . "\" took " . round($tickMs, 2) . "ms, setting tick rate to " . $level->getTickRate() . " ticks");
						}
						$level->tickRateCounter = $level->getTickRate();
					}
				}
			}catch(\Throwable $e){
				$this->logger->critical($this->getLanguage()->translateString("pocketmine.level.tickError", [$level->getName(), $e->getMessage()]));
				if(\pocketmine\DEBUG > 1 && $this->logger instanceof MainLogger){
					$this->logger->logException($e);
				}
			}
		}
	}

	public function doAutoSave(){
		if($this->getAutoSave()){
			Timings::$worldSaveTimer->startTiming();
			foreach($this->players as $index => $player){
				if($player->isOnline()){
					$player->save(true);
				}elseif(!$player->isConnected()){
					$this->removePlayer($player);
				}
			}

			foreach($this->getLevels() as $level){
				$level->save(false);
			}
			Timings::$worldSaveTimer->stopTiming();
		}
	}

	public function sendUsage($type = SendUsageTask::TYPE_STATUS){
		$this->scheduler->scheduleAsyncTask(new SendUsageTask($this, $type, $this->uniquePlayers));
		$this->uniquePlayers = [];
	}

	/**
	 * @return BaseLang
	 */
	public function getLanguage(){
		return $this->baseLang;
	}

	/**
	 * @return bool
	 */
	public function isLanguageForced(){
		return $this->forceLanguage;
	}

	/**
	 * @return Network
	 */
	public function getNetwork(){
		return $this->network;
	}

	/**
	 * @return MemoryManager
	 */
	public function getMemoryManager(){
		return $this->memoryManager;
	}

	private function titleTick(){
		if(!Terminal::hasFormattingCodes()){
			return;
		}

		$d = Utils::getRealMemoryUsage();

		$u = Utils::getMemoryUsage(true);
		$usage = round(($u[0] / 1024) / 1024, 2) . "/" . round(($d[0] / 1024) / 1024, 2) . "/" . round(($u[1] / 1024) / 1024, 2) . "/" . round(($u[2] / 1024) / 1024, 2) . " MB @ " . Utils::getThreadCount() . " threads";

		echo "\x1b]0;" . $this->getName() . $this->getFormattedVersion("-") .
			" | Online " . count($this->players) . "/" . $this->getMaxPlayers() .
			" | Memory " . $usage .
			" | U " . round($this->network->getUpload() / 1024, 2) .
			" D " . round($this->network->getDownload() / 1024, 2) .
			" kB/s | TPS " . $this->getTicksPerSecondAverage() .
			" | Load " . $this->getTickUsageAverage() . "%\x07";

		$this->network->resetStatistics();
	}

	/**
	 * @param      $variable
	 * @param null $defaultValue
	 * @return bool|mixed|null
	 */
	public function getAdvancedProperty($variable, $defaultValue = null, Config $cfg = null){
		$vars = explode(".", $variable);
		$base = array_shift($vars);

		if($cfg == null) {
			$cfg = $this->advancedConfig;
		}

		if($cfg->exists($base)){
			$base = $cfg->get($base);
		}else{
			return $defaultValue;
		}

		while(count($vars) > 0){
			$baseKey = array_shift($vars);
			if(is_array($base) && isset($base[$baseKey])){
				$base = $base[$baseKey];
			}else{
				return $defaultValue;
			}
		}

		return $base;
	}

	public function updateQuery(){
		try{
			$this->getPluginManager()->callEvent($this->queryRegenerateTask = new QueryRegenerateEvent($this, 5));
			if($this->queryHandler !== null){
				$this->queryHandler->regenerateInfo();
			}
		}catch(\Throwable $e){
			$this->logger->logException($e);
		}
	}

	/**
	 * Tries to execute a server tick
	 */
	private function tick(){
		$tickTime = microtime(true);
		if(($tickTime - $this->nextTick) < -0.025){ //Allow half a tick of diff
			return false;
		}

		Timings::$serverTickTimer->startTiming();

		++$this->tickCounter;

		$this->checkConsole();

		Timings::$connectionTimer->startTiming();
		$this->network->processInterfaces();

		if($this->rcon !== null){
			$this->rcon->check();
		}

		Timings::$connectionTimer->stopTiming();

		Timings::$schedulerTimer->startTiming();
		$this->scheduler->mainThreadHeartbeat($this->tickCounter);
		Timings::$schedulerTimer->stopTiming();

		$this->checkTickUpdates($this->tickCounter, $tickTime);

		foreach($this->players as $player){
			$player->checkNetwork();
		}

		if(($this->tickCounter & 0b1111) === 0){
			$this->titleTick();
			$this->maxTick = 20;
			$this->maxUse = 0;

			if(($this->tickCounter & 0b111111111) === 0){
				if(($this->dserverConfig["enable"] && $this->dserverConfig["queryTickUpdate"]) || !$this->dserverConfig["enable"]){
					$this->updateQuery();
				}
			}

			$this->getNetwork()->updateName();
		}

		if($this->autoSave && ++$this->autoSaveTicker >= $this->autoSaveTicks){
			$this->autoSaveTicker = 0;
			$this->doAutoSave();
		}

		/*if($this->sendUsageTicker > 0 and --$this->sendUsageTicker === 0){
			$this->sendUsageTicker = 6000;
			$this->sendUsage(SendUsageTask::TYPE_STATUS);
		}*/

		if(($this->tickCounter % 100) === 0){
			foreach($this->levels as $level){
				$level->clearCache();
			}

			if($this->getTicksPerSecondAverage() < 1){
				$this->logger->warning($this->getLanguage()->translateString("pocketmine.server.tickOverload"));
			}
		}

		if($this->dispatchSignals && $this->tickCounter % 5 === 0){
			pcntl_signal_dispatch();
		}

		$this->getMemoryManager()->check();

		Timings::$serverTickTimer->stopTiming();

		$now = microtime(true);
		$tick = min(20, 1 / max(0.001, $now - $tickTime));
		$use = min(1, ($now - $tickTime) / 0.05);

		//TimingsHandler::tick($tick <= $this->profilingTickRate);

		if($this->maxTick > $tick){
			$this->maxTick = $tick;
		}

		if($this->maxUse < $use){
			$this->maxUse = $use;
		}

		array_shift($this->tickAverage);
		$this->tickAverage[] = $tick;
		array_shift($this->useAverage);
		$this->useAverage[] = $use;
		$this->getTickSleeper()->resetNotificationProcessingTime();

		if(($this->nextTick - $tickTime) < -1){
			$this->nextTick = $tickTime;
		}else{
			$this->nextTick += 0.05;
		}

		return true;
	}

}

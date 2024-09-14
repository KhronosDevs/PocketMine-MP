<?php



namespace pocketmine\plugin;

use pocketmine\event\plugin\PluginDisableEvent;
use pocketmine\event\plugin\PluginEnableEvent;
use pocketmine\Server;
use pocketmine\utils\MainLogger;
use pocketmine\utils\TextFormat;
use function class_exists;
use function dirname;
use function file_exists;
use function file_get_contents;
use function is_dir;
use function trigger_error;
use const DIRECTORY_SEPARATOR;
use const E_USER_WARNING;

class FolderPluginLoader implements PluginLoader{

	/** @var Server */
	private $server;

	public function __construct(Server $server){
		$this->server = $server;
	}

	/**
	 * Loads the plugin contained in $file
	 *
	 * @param string $file
	 *
	 * @return Plugin
	 */
	public function loadPlugin($file)
	{
		if(is_dir($file) && file_exists($file . "/plugin.yml") && file_exists($file . "/src/")){
			if(($description = $this->getPluginDescription($file)) instanceof PluginDescription){
				MainLogger::getLogger()->info(TextFormat::LIGHT_PURPLE . "Loading source plugin " . $description->getFullName());
				$dataFolder = dirname($file) . DIRECTORY_SEPARATOR . $description->getName();
				if(file_exists($dataFolder) && !is_dir($dataFolder)){
					trigger_error("Projected dataFolder '" . $dataFolder . "' for " . $description->getName() . " exists and is not a directory", E_USER_WARNING);

					return null;
				}

				$className = $description->getMain();
				$this->server->getLoader()->addPath($file . "/src");

				if (class_exists($className, true)){
					$plugin = new $className();
					$this->initPlugin($plugin, $description, $dataFolder, $file);

					return $plugin;
				}else{
					trigger_error("Couldn't load source plugin " . $description->getName() . ": main class not found", E_USER_WARNING);

					return null;
				}
			}
		}

		return null;
	}

	/**
	 * Gets the PluginDescription from the file
	 *
	 * @param string $file
	 *
	 * @return PluginDescription
	 */
	public function getPluginDescription($file){
		if(is_dir($file) && file_exists($file . "/plugin.yml")){
			$yaml = @file_get_contents($file . "/plugin.yml");
			if($yaml != ""){
				return new PluginDescription($yaml);
			}
		}

		return null;
	}

	/**
	 * Returns the filename patterns that this loader accepts
	 *
	 * @return array
	 */
	public function getPluginFilters(){
		return "/[^\\.]/";
	}

	/**
	 * @param string $dataFolder
	 * @param string $file
	 */
	private function initPlugin(PluginBase $plugin, PluginDescription $description, $dataFolder, $file){
		$plugin->init($this, $this->server, $description, $dataFolder, $file);
		$plugin->onLoad();
	}

	public function enablePlugin(Plugin $plugin){
		if($plugin instanceof PluginBase && !$plugin->isEnabled()){
			MainLogger::getLogger()->info("Enabling " . $plugin->getDescription()->getFullName());

			$plugin->setEnabled(true);

			Server::getInstance()->getPluginManager()->callEvent(new PluginEnableEvent($plugin));
		}
	}

	public function disablePlugin(Plugin $plugin){
		if($plugin instanceof PluginBase && $plugin->isEnabled()){
			MainLogger::getLogger()->info("Disabling " . $plugin->getDescription()->getFullName());

			Server::getInstance()->getPluginManager()->callEvent(new PluginDisableEvent($plugin));

			$plugin->setEnabled(false);
		}
	}
}

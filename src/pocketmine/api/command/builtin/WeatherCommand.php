<?php

declare(strict_types=1);

namespace pocketmine\api\command\builtin;

use pocketmine\api\command\CommandSender;
use pocketmine\api\command\Format;
use pocketmine\core\resource\WorldConfig;
use pocketmine\core\system\WeatherSystem;

/**
 * /weather [<clear|rain|thunder|storm> [duration]] — shows or sets the world
 * weather. Setting a state pins it for the given duration (ticks, default
 * 12000 = 10 minutes); the per-poll LevelEventPacket broadcast syncs every
 * client within one poll. Legacy name mapping: clear/sunny/fine, rain/rainy,
 * thunder, storm/rain_thunder/rainy_thunder.
 */
final class WeatherCommand extends BuiltinCommand {
    private const NAMES = [
        WeatherSystem::CLEAR => 'clear',
        WeatherSystem::RAIN => 'rain',
        WeatherSystem::RAINY_THUNDER => 'storm',
        WeatherSystem::THUNDER => 'thunder',
    ];

    public function __construct() {
        parent::__construct(
            'weather',
            'Show or set the world weather',
            '/weather [<clear|rain|thunder|storm> [duration]]',
            [],
            'khronos.command.weather',
            category: 'world',
        );
    }

    public function execute(CommandSender $sender, array $args): bool {
        $kernel = $this->kernel();
        if ($kernel === null) {
            return false;
        }
        $config = $kernel->getWorld()->getResourceRegistry()->get(WorldConfig::class);
        if (!$config instanceof WorldConfig) {
            return false;
        }

        $type = array_shift($args) ?? '';
        if ($type === '') {
            $sender->sendMessage('Weather is ' . self::NAMES[$config->weather] . '.');
            return true;
        }

        $weather = match (strtolower($type)) {
            'clear', 'sunny', 'fine' => WeatherSystem::CLEAR,
            'rain', 'rainy' => WeatherSystem::RAIN,
            'thunder' => WeatherSystem::THUNDER,
            'storm', 'rain_thunder', 'rainy_thunder' => WeatherSystem::RAINY_THUNDER,
            default => -1,
        };
        if ($weather < 0) {
            $sender->sendMessage('Weather must be clear, rain, thunder or storm.');
            return false;
        }

        $duration = 12000; // ticks; legacy default spell length
        if (isset($args[0]) && ctype_digit($args[0])) {
            $duration = (int)$args[0];
        }
        if ($duration <= 0) {
            $sender->sendMessage('Duration must be a positive number of ticks.');
            return false;
        }

        $config->weather = $weather;
        $config->weatherDuration = $duration;
        if (!WeatherSystem::isThundering($weather)) {
            $config->lightningTick = 0; // no timer outside storms
        }
        $sender->sendMessage(Format::success('Weather set to ' . Format::VALUE . self::NAMES[$weather] . Format::SUCCESS . " for " . Format::VALUE . $duration . Format::SUCCESS . ' ticks.'));
        return true;
    }
}

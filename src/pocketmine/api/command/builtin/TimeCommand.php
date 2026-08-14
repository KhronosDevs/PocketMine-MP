<?php

declare(strict_types=1);

namespace pocketmine\api\command\builtin;

use pocketmine\api\command\CommandSender;
use pocketmine\core\resource\WorldConfig;

/**
 * /time set <value|day|night> — sets the world clock (0-23999 ticks; day =
 * 1000, night = 13000). The per-poll SetTimePacket broadcast syncs every
 * client within one poll.
 */
final class TimeCommand extends BuiltinCommand {
    public function __construct() {
        parent::__construct(
            'time',
            'Set the world time',
            '/time set <value|day|night>',
            [],
            'khronos.command.time',
        );
    }

    public function execute(CommandSender $sender, array $args): bool {
        if (($args[0] ?? '') === 'set') {
            array_shift($args);
        }
        $arg = array_shift($args) ?? '';

        $value = match (strtolower($arg)) {
            'day' => 1000,
            'noon' => 6000,
            'sunset' => 12000,
            'night' => 13000,
            'midnight' => 18000,
            default => ctype_digit($arg) ? (int)$arg : -1,
        };
        if ($value < 0 || $value >= 24000) {
            $sender->sendMessage('Time must be a value 0-23999, or day/night.');
            return false;
        }

        $kernel = $this->kernel();
        if ($kernel === null) {
            return false;
        }
        $config = $kernel->getWorld()->getResourceRegistry()->get(WorldConfig::class);
        if (!$config instanceof WorldConfig) {
            return false;
        }

        $config->time = $value;
        $sender->sendMessage("Time set to $value.");
        return true;
    }
}

<?php

declare(strict_types=1);

namespace pocketmine\api\command\builtin;

use pocketmine\api\command\CommandSender;
use pocketmine\api\command\Format;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\constants\MetadataKeys;
use pocketmine\core\enum\GameMode;

/**
 * /gamemode <0|1|2|3|survival|creative|adventure|spectator> [player] —
 * switches a player between survival, creative, adventure and spectator.
 * Gamemode lives on the entity's metadata (the same field BlockBreakService /
 * Hunger read for instant break and no food drain); the client is flipped via
 * an AdventureSettingsPacket.
 */
final class GamemodeCommand extends BuiltinCommand {
    public function __construct() {
        parent::__construct(
            'gamemode',
            'Change a player\'s gamemode',
            '/gamemode <0|1|2|3|survival|creative|adventure|spectator> [player]',
            ['gm'],
            'khronos.command.gamemode',
            category: 'player',
        );
    }

    public function execute(CommandSender $sender, array $args): bool {
        $modeArg = array_shift($args) ?? '';
        $mode = match (strtolower($modeArg)) {
            '0', 'survival', 's' => GameMode::Survival,
            '1', 'creative', 'c' => GameMode::Creative,
            '2', 'adventure', 'a' => GameMode::Adventure,
            '3', 'spectator', 'sp' => GameMode::Spectator,
            default => null,
        };
        if ($mode === null) {
            $sender->sendMessage(Format::error("Unknown gamemode \"$modeArg\" — use survival, creative, adventure, or spectator."));
            return false;
        }

        $targetId = $this->resolvePlayerId($sender, array_shift($args) ?? null);
        if ($targetId === null) {
            $sender->sendMessage(Format::error('Player not found.'));
            return false;
        }

        $kernel = $this->kernel();
        if ($kernel === null) {
            return false;
        }
        $metadata = $kernel->getWorld()->getEntity($targetId)?->get(MetadataComponent::class);
        if ($metadata === null) {
            return false;
        }

        // Blocker 4 audit: cancellable PlayerGameModeChangeEvent fires before
        // the metadata mutates - a plugin can keep the old gamemode.
        $event = new \pocketmine\api\event\PlayerGameModeChangeEvent(
            new \pocketmine\api\entity\Player(
                \pocketmine\core\ecs\EntityRef::create($targetId, $kernel->getWorld()),
                $kernel->getWorld(),
            ),
            $mode,
        );
        $kernel->getEventPort()->emit($event);
        if ($event->isCancelled()) {
            $sender->sendMessage(Format::error('The gamemode change was cancelled.'));
            return false;
        }
        $metadata->set(MetadataKeys::GAMEMODE, $event->getNewGameMode()->value);
        $kernel->getNetworkSessionService()->sendGamemodeTo($targetId, $event->getNewGameMode());

        $name = $this->playerName($targetId);
        $label = match ($mode) {
            GameMode::Creative => 'creative',
            GameMode::Adventure => 'adventure',
            GameMode::Spectator => 'spectator',
            default => 'survival',
        };
        $sender->sendMessage(Format::success($name . ' is now in ' . Format::VALUE . $label . Format::SUCCESS . ' mode.'));
        return true;
    }
}

<?php

declare(strict_types=1);

namespace pocketmine\api\command\builtin;

use pocketmine\api\command\CommandSender;
use pocketmine\core\component\MetadataComponent;
use pocketmine\core\constants\MetadataKeys;
use pocketmine\core\enum\GameMode;

/**
 * /gamemode <0|1|survival|creative> [player] — switches a player between
 * survival and creative. Gamemode lives on the entity's metadata (the same
 * field BlockBreakService / Hunger read for instant break and no food drain);
 * the client is flipped via an AdventureSettingsPacket.
 */
final class GamemodeCommand extends BuiltinCommand {
    public function __construct() {
        parent::__construct(
            'gamemode',
            'Change a player\'s gamemode',
            '/gamemode <0|1|survival|creative> [player]',
            ['gm'],
            'khronos.command.gamemode',
        );
    }

    public function execute(CommandSender $sender, array $args): bool {
        $modeArg = array_shift($args) ?? '';
        $mode = match (strtolower($modeArg)) {
            '0', 'survival', 's' => GameMode::Survival,
            '1', 'creative', 'c' => GameMode::Creative,
            default => null,
        };
        if ($mode === null) {
            $sender->sendMessage("Unknown gamemode \"$modeArg\" - use 0/survival or 1/creative.");
            return false;
        }

        $targetId = $this->resolvePlayerId($sender, array_shift($args) ?? null);
        if ($targetId === null) {
            $sender->sendMessage('Player not found.');
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

        $metadata->set(MetadataKeys::GAMEMODE, $mode->value);
        $kernel->getNetworkSessionService()->sendGamemodeTo($targetId, $mode);

        $name = $this->playerName($targetId);
        $sender->sendMessage($mode === GameMode::Creative ? "$name is now in creative mode." : "$name is now in survival mode.");
        return true;
    }
}

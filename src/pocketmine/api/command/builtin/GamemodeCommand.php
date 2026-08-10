<?php

declare(strict_types=1);

namespace pocketmine\api\command\builtin;

use pocketmine\api\command\CommandSender;
use pocketmine\core\component\MetadataComponent;

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
        );
    }

    public function execute(CommandSender $sender, array $args): bool {
        $modeArg = array_shift($args) ?? '';
        $mode = match (strtolower($modeArg)) {
            '0', 'survival', 's' => 0,
            '1', 'creative', 'c' => 1,
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

        $metadata->set('gamemode', $mode);
        $kernel->getNetworkSessionService()->sendGamemodeTo($targetId, $mode);

        $name = $this->playerName($targetId);
        $sender->sendMessage($mode === 1 ? "$name is now in creative mode." : "$name is now in survival mode.");
        return true;
    }
}

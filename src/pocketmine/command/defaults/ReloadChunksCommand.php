<?php

namespace pocketmine\command\defaults;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\Player;
use pocketmine\utils\TextFormat;
use pocketmine\level\Level;

class ReloadChunksCommand extends VanillaCommand {

    public function __construct($name){
        parent::__construct(
            $name,
            "recargar chunks",
            "/reloadchunks"
        );
    }

    public function execute(CommandSender $sender, $label, array $args){
        if(!$sender instanceof Player){
            $sender->sendMessage("solo jugadores");
            return false;
        }
        $r = 3;
        $level = $sender->getLevel();
        $chunkX = $sender->getFloorX() >> 4;
        $chunkZ = $sender->getFloorZ() >> 4;
      
        for($x = -$r; $x <= $r; $x++) {
            for($z = -$r; $z <= $r; $z++) {
                $level->unloadChunk($chunkX + $x, $chunkZ + $z, true);
                $level->loadChunk($chunkX + $x, $chunkZ + $z, true);
            }
        }
        $sender->sendMessage("§fChunks recargados!");
        return true;
    }
}

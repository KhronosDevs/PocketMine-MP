<?php

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\level\Level;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\utils\TextFormat;

class TestChunkLoadCommand extends VanillaCommand
{

    public function __construct(string $name){
        parent::__construct($name, "Test async chunk loading", "/testchunkload <x> <z> [world]");
    }

    public function execute(CommandSender $sender, $commandLabel, array $args)
    {
        if (!$this->testPermission($sender)) {
            return true;
        }

        if (count($args) < 2) {
            $sender->sendMessage(TextFormat::RED . "Uso: /testchunkload <x> <z> [world]");
            return true;
        }

        $x = (int) $args[0];
        $z = (int) $args[1];

        if (isset($args[2])) {
            $level = Server::getInstance()->getLevelByName($args[2]);
        } elseif ($sender instanceof Player) {
            $level = $sender->getLevel();
        } else {
            $level = Server::getInstance()->getDefaultLevel();
        }

        if ($level === null) {
            $sender->sendMessage(TextFormat::RED . "Mundo no encontrado.");
            return true;
        }

        $sender->sendMessage(TextFormat::YELLOW . "Probando carga async del chunk ($x, $z) en " . $level->getName() . "...");

        $t0 = microtime(true);

        // forzamos a que no esté ya cargado, para medir bien
        if ($level->isChunkLoaded($x, $z)) {
            $sender->sendMessage(TextFormat::GRAY . "(el chunk ya estaba cargado, el resultado no va a ser representativo)");
        }

        $ok = $level->loadChunkAsync($x, $z);

        if (!$ok) {
            $sender->sendMessage(TextFormat::RED . "loadChunkAsync devolvió false — el chunk no existe generado en disco todavía.");
            return true;
        }

        $sender->sendMessage(TextFormat::GREEN . "Task encolada en " . round((microtime(true) - $t0) * 1000, 3) . "ms. Mirá la consola para el callback.");

        // hack simple para loguear cuando el chunk realmente aparezca cargado
        Server::getInstance()->getScheduler()->scheduleDelayedRepeatingTask(
            new class($level, $x, $z, $t0, $sender) extends \pocketmine\scheduler\Task {
                private $level;
                private $x;
                private $z;
                private $t0;
                private $sender;
                private $ticks = 0;

                public function __construct(Level $level, $x, $z, $t0, CommandSender $sender)
                {
                    $this->level = $level;
                    $this->x = $x;
                    $this->z = $z;
                    $this->t0 = $t0;
                    $this->sender = $sender;
                }

                public function onRun($currentTick)
                {
                    $this->ticks++;
                    if ($this->level->isChunkLoaded($this->x, $this->z)) {
                        $elapsed = round((microtime(true) - $this->t0) * 1000, 3);
                        $this->sender->sendMessage(TextFormat::GREEN . "Chunk cargado async en {$elapsed}ms ({$this->ticks} ticks)");
                        Server::getInstance()->getScheduler()->cancelTask($this->getTaskId());
                    } elseif ($this->ticks > 200) { // ~10s de timeout
                        $this->sender->sendMessage(TextFormat::RED . "Timeout esperando el chunk — algo falló.");
                        Server::getInstance()->getScheduler()->cancelTask($this->getTaskId());
                    }
                }
            },
            1,
            1
        );

        return true;
    }
}

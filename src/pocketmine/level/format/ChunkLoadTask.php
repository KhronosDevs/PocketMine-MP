<?php

namespace pocketmine\level\format;

use pocketmine\level\Level;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use function fclose;
use function fopen;
use function fread;
use function fseek;
use function is_resource;
use function strlen;
use function unpack;

class ChunkLoadTask extends AsyncTask
{

    public $levelId;
    public $chunkX;
    public $chunkZ;
    public $chunkClass;
    public $filePath;
    public $sector;
    public $success = false;

    public function __construct(Level $level, int $chunkX, int $chunkZ, string $chunkClass, string $filePath, int $sector)
    {
        $this->levelId = $level->getId();
        $this->chunkX = $chunkX;
        $this->chunkZ = $chunkZ;
        $this->chunkClass = $chunkClass;
        $this->filePath = $filePath;
        $this->sector = $sector;
    }

    public function onRun()
    {
        $fp = fopen($this->filePath, "rb");
        if (!is_resource($fp)) {
            return;
        }

        fseek($fp, $this->sector << 12);
        $lenData = fread($fp, 4);
        if (strlen($lenData) < 4) {
            fclose($fp);
            return;
        }
        $len = unpack("N", $lenData)[1];
        fread($fp, 1); //compression byte
        $data = fread($fp, $len - 1);
        fclose($fp);

        if ($data === false || $data === "") {
            return;
        }

        //la parte pesada (zlib_decode + construir todo el arbol NBT + 8 ChunkSection)
        //corre acá, en el worker, no en el hilo principal
        $chunkClass = $this->chunkClass;
        $chunk = $chunkClass::fromBinary($data, null);
        if ($chunk === null) {
            return;
        }

        $this->setResult($chunk->toFastBinary(), false);
        $this->success = true;
    }

    public function onCompletion(Server $server)
    {
        $level = $server->getLevel($this->levelId);
        if ($level === null || !$this->success || !$this->hasResult()) {
            return;
        }

        $chunkClass = $this->chunkClass;
        $chunk = $chunkClass::fromFastBinary($this->getResult(), $level->getProvider());
        if ($chunk !== null) {
            $level->chunkLoadCallback($this->chunkX, $this->chunkZ, $chunk);
        }
    }
}

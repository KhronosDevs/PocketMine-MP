<?php

use pocketmine\network\protocol\Info;
use pocketmine\utils\Git;
use pocketmine\utils\Terminal;

$currentWorkingDirectory = dirname(__DIR__);
$directorySeparator = DIRECTORY_SEPARATOR;

require_once("{$currentWorkingDirectory}/src/pocketmine/utils/Git.php");
require_once("{$currentWorkingDirectory}/src/pocketmine/utils/Process.php");
require_once("{$currentWorkingDirectory}/src/pocketmine/utils/Terminal.php");
require_once("{$currentWorkingDirectory}/src/pocketmine/utils/Utils.php");
require_once("{$currentWorkingDirectory}/src/pocketmine/network/protocol/Info.php");

Terminal::init();

$pharPath = $currentWorkingDirectory . DIRECTORY_SEPARATOR . "PocketMine-MP.phar";

if (file_exists($pharPath)) {
    echo Terminal::$COLOR_YELLOW . 'Phar file already exists, overwriting...' . PHP_EOL;
    @unlink($pharPath);
}

$phar = new \Phar($pharPath);
$phar->setMetadata([
    "name" => 'Khronos',
    "version" => Git::getRepositoryStatePretty($currentWorkingDirectory),
    "api" => '2.0.0',
    "minecraft" => 'v0.15.10 alpha',
    "protocol" => Info::CURRENT_PROTOCOL,
    "creationDate" => time()
]);

$phar->setStub('<?php define("pocketmine\\\\PATH", "phar://". __FILE__ ."/"); require_once("phar://". __FILE__ ."/src/pocketmine/PocketMine.php");  __HALT_COMPILER();');
$phar->setSignatureAlgorithm(\Phar::SHA1);
$phar->startBuffering();

$filePath = substr($currentWorkingDirectory, 0, 7) === "phar://" ? $currentWorkingDirectory : realpath($currentWorkingDirectory) . "/";
$filePath = rtrim(str_replace("\\", "/", $filePath), "/") . "/";
foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($filePath . "src")) as $file) {
    $path = ltrim(str_replace(["\\", $filePath], ["/", ""], $file), "/");
    if ($path{
    0} === "." or strpos($path, "/.") !== false or substr($path, 0, 4) !== "src/") {
        continue;
    }
    $phar->addFile($file, $path);

    echo Terminal::$COLOR_GREEN . 'Adding ' . Terminal::$COLOR_GRAY . $path . PHP_EOL;
}
foreach ($phar as $file => $fileInfo) {
    /** @var \PharFileInfo $fileInfo */
    if ($fileInfo->getSize() > (1024 * 512)) {
        $fileInfo->compress(\Phar::GZ);
    }

}

$phar->stopBuffering();

echo Terminal::$COLOR_GREEN . 'Build finished.';

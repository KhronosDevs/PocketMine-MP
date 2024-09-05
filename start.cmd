@echo off
title PocketMine-MP server software for Minecraft: Pocket Edition

:: Change to the script's directory
cd /d "%~dp0"

:: Check if PHP binary exists
if exist "bin\php7\bin\php.exe" (
    set "PHP_BINARY=bin\php7\bin\php.exe"
) else (
    set "PHP_BINARY=php"
)

:: Check if PocketMine-MP.phar exists
if exist "PocketMine-MP.phar" (
    set "POCKETMINE_FILE=PocketMine-MP.phar"
) else if exist "src\pocketmine\PocketMine.php" (
    set "POCKETMINE_FILE=src\pocketmine\PocketMine.php"
) else (
    echo Couldn't find a valid PocketMine-MP installation
    exit /b 1
)

:: Check if mintty exists
if exist "bin\mintty.exe" (
    :: Use mintty to open a new terminal and run the command
    bin\mintty.exe -o Columns=130 -o Rows=32 -o AllowBlinking=0 -o FontQuality=3 -o Font="DejaVu Sans Mono" -o FontHeight=10 -o CursorType=0 -o CursorBlinks=1 -h error -t "PocketMine-MP" -i bin\pocketmine.ico "%PHP_BINARY%" "%POCKETMINE_FILE%" --enable-ansi %*
) else (
    :: Run the PHP script directly
    "%PHP_BINARY%" -c bin\php7\bin "%POCKETMINE_FILE%" %*
)


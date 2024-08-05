#!/bin/bash

# Title for terminal (Note: This won't affect most terminals)
# For some terminals, you can use escape sequences to set the title, but it's not universally supported.
# Example for xterm: 
# echo -ne "\033]0;PocketMine-MP server software for Minecraft: Pocket Edition\007"

# Change to the script's directory
cd "$(dirname "$0")"

# Check if PHP binary exists
if [ -f "bin/php7/bin/php" ]; then
    PHP_BINARY="bin/php7/bin/php"
else
    PHP_BINARY="php"
fi

# Check if PocketMine-MP.phar exists
if [ -f "PocketMine-MP.phar" ]; then
    POCKETMINE_FILE="PocketMine-MP.phar"
elif [ -f "src/pocketmine/PocketMine.php" ]; then
    POCKETMINE_FILE="src/pocketmine/PocketMine.php"
else
    echo "Couldn't find a valid PocketMine-MP installation"
    exit 1
fi

# Check if mintty exists
if [ -f "bin/mintty" ]; then
    # Use mintty to open a new terminal and run the command
    # Adjust mintty options as needed
    bin/mintty -o Columns=130 -o Rows=32 -o AllowBlinking=0 -o FontQuality=3 -o Font="DejaVu Sans Mono" -o FontHeight=10 -o CursorType=0 -o CursorBlinks=1 -h error -t "PocketMine-MP" -i bin/pocketmine.ico "$PHP_BINARY" "$POCKETMINE_FILE" --enable-ansi "$@"
else
    # Run the PHP script directly
    "$PHP_BINARY" -c bin/php "$POCKETMINE_FILE" "$@"
fi

#!/bin/bash

# Change to the script's directory
cd "$(dirname "$0")"

# Check if PHP binary exists
if [ -f "bin/php7/bin/php" ]; then
    PHP_BINARY="bin/php7/bin/php"
else
    PHP_BINARY="php"
fi

# The new ECS server entry point (the old PocketMine.php was removed in the
# Phase-8 API rewrite).
POCKETMINE_FILE="bootstrap.php"

# Run the server. bootstrap.php enables networking (binds UDP :19132) and
# enters the kernel run loop. The memory floor matches legacy PocketMine
# (512M): a client joining at a large view distance generates hundreds of
# chunks (~200KB resident each), far beyond the 128M PHP CLI default.
# FFI enables the native acceleration library (native/kh_native.so): light
# calc, terrain noise and nibble pack run in C when available, falling back
# to pure PHP otherwise (khronos.json "native-accel.enabled"). The bundled
# php-binaries ship ffi.so; stock PHP warns and keeps running.
"$PHP_BINARY" -d memory_limit=512M -d extension=ffi -d ffi.enable=1 "$POCKETMINE_FILE" "$@"

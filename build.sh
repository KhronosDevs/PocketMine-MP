#!/bin/bash

CWD=$( cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )

if [ ! -d "$CWD/bin" ]; then
  echo "You must have a bin folder in your current working directory ($CWD)"
  exit 1
fi

PHP_BINARY="$CWD/bin/php7/bin/php"

if [ ! -f "$PHP_BINARY" ]; then
  echo "It was not possible to find the php binary at $PHP_BINARY"
fi

"$PHP_BINARY" -c bin/php7/bin -d phar.readonly=0 "$CWD/build/make-server.php"
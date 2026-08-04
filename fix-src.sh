#!/bin/bash

CWD=$( cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )

php82 php-cs-fixer fix \
    --config="$CWD/.php-cs-fixer.php" \
    "$CWD/src"

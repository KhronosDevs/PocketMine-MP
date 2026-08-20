#!/bin/bash

CWD=$( cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )

"$CWD/bin/php7/bin/php" php-cs-fixer fix \
    --config="$CWD/.php-cs-fixer.php" \
    "$CWD/src"

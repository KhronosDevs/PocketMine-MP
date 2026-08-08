<?php

declare(strict_types=1);

/**
 * Rebuilds tests/fixtures/demo-plugin.phar from tests/fixtures/phar-plugin/.
 *
 * Usage (phar.readonly must be off at startup):
 *   bin/php7/bin/php -d phar.readonly=0 tests/fixtures/build_phar.php
 */

$source = __DIR__ . '/phar-plugin';
$out = __DIR__ . '/demo-plugin.phar';

if (!is_dir($source)) {
    fwrite(STDERR, "Source plugin directory not found: $source\n");
    exit(1);
}

if (file_exists($out)) {
    unlink($out);
}

$phar = new Phar($out);
$phar->startBuffering();
$phar->buildFromDirectory($source);
$phar->setStub("<?php __HALT_COMPILER();");
$phar->stopBuffering();

echo "Built $out (" . filesize($out) . " bytes)\n";

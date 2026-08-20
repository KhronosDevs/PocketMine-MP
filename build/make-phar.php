<?php
/**
 * build/make-phar.php — Compile Khronos into PocketMine-MP.phar
 *
 * Usage:
 *   bin/php7/bin/php -d phar.readonly=0 build/make-phar.php
 *
 * The phar bundles:
 *   - src/pocketmine/   (all PHP files)
 *   - src/raklib/       (all PHP files)
 *   - vendor/           (composer autoload)
 *   - autoload.php
 *   - khronos.json      (default config, copied in)
 *
 * NOT bundled (must sit next to the phar at runtime):
 *   - bin/php7/         (the PHP runtime)
 *   - native/*.so       (FFI shared objects — can't load .so from inside a phar)
 *   - worlds/           (persisted world data)
 *   - plugins/          (plugin directory)
 *   - server.properties, banned-*.txt, ops.txt, white-list.txt
 *
 * The stub replaces bootstrap.php: it sets up the autoloader, calls
 * bootstrap(), enables networking, and enters the kernel run loop.
 */

declare(strict_types=1);

if (PHP_INT_SIZE !== 8) {
    fwrite(STDERR, "Phar build requires 64-bit PHP.\n");
    exit(1);
}

$startTime = microtime(true);
$rootDir   = dirname(__DIR__);
$pharPath  = $rootDir . '/PocketMine-MP.phar';

echo "=== Khronos Phar Builder ===\n";
echo "Source: {$rootDir}\n";
echo "Output: {$pharPath}\n\n";

// ─── Collect files ──────────────────────────────────────────────────

$files = [];

// 1. src/ — all PHP files under pocketmine/ and raklib/
foreach (['src/pocketmine', 'src/raklib'] as $srcDir) {
    $realDir = $rootDir . '/' . $srcDir;
    if (!is_dir($realDir)) {
        fwrite(STDERR, "Missing directory: {$realDir}\n");
        exit(1);
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($realDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $rel = $srcDir . '/' . substr($file->getPathname(), strlen($realDir) + 1);
            $files[$rel] = $file->getPathname();
        }
    }
}

// 2. vendor/ — composer autoload (all PHP)
$vendorDir = $rootDir . '/vendor';
if (is_dir($vendorDir)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($vendorDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $rel = 'vendor/' . substr($file->getPathname(), strlen($vendorDir) + 1);
            $files[$rel] = $file->getPathname();
        }
    }
}

// 3. autoload.php (root)
if (file_exists($rootDir . '/autoload.php')) {
    $files['autoload.php'] = $rootDir . '/autoload.php';
}

// 4. khronos.json (default config)
if (file_exists($rootDir . '/khronos.json')) {
    $files['khronos.json'] = $rootDir . '/khronos.json';
}

// 5. Resource JSON files (creativeitems.json, etc.)
$resourceDir = $rootDir . '/src/pocketmine/core/resource';
if (is_dir($resourceDir)) {
    foreach (glob($resourceDir . '/*.json') as $json) {
        $rel = 'src/pocketmine/core/resource/' . basename($json);
        if (!isset($files[$rel])) {
            $files[$rel] = $json;
        }
    }
}

echo "Collected " . count($files) . " files to bundle.\n\n";

// ─── Size report ────────────────────────────────────────────────────

$totalSize = 0;
foreach ($files as $path) {
    $totalSize += filesize($path);
}
echo "Total source size: " . number_format($totalSize) . " bytes (" .
     number_format($totalSize / 1024, 1) . " KB)\n\n";

// ─── Fix vendor autoload paths ──────────────────────────────────────
// Composer's autoload_static.php stores __DIR__ paths that resolve to the
// build machine. Inside a phar, those resolve to "phar://..." which is fine
// as long as the relative layout is preserved. The PSR-4 mappings point to
// $baseDir/src/{pocketmine,raklib} — since we keep the same directory
// layout inside the phar, the autoloader works without patching.

// ─── Build the phar ─────────────────────────────────────────────────

if (file_exists($pharPath)) {
    unlink($pharPath);
}

$phar = new Phar($pharPath);
$phar->setStub(<<<'STUB'
<?php
/**
 * Khronos PocketMine-MP.phar stub
 *
 * Replaces bootstrap.php when running from the phar.
 * The data directory is the directory containing the phar file.
 */

declare(strict_types=1);

// Phar::running() returns the full phar path; dirname() gives the data dir.
// __DIR__ in the stub context is also the data dir (parent of the phar).
$dataDir = __DIR__;
chdir($dataDir);

// Find the phar file: when run as `php PocketMine-MP.phar`, __DIR__ is the
// directory containing the phar and Phar::running() may be empty in some
// SAPI builds. Fall back to scanning the CWD for a .phar file.
$pharPath = Phar::running();
if ($pharPath === '' || $pharPath === false) {
    // __DIR__ is the data directory; the phar lives right next to us.
    $candidates = glob($dataDir . '/*.phar');
    $pharPath = $candidates !== false && count($candidates) > 0
        ? $candidates[0]
        : $dataDir . '/PocketMine-MP.phar';
}

require 'phar://' . $pharPath . '/autoload.php';

use function pocketmine\bootstrap;

$kernel = bootstrap();
$kernel->setNetworkingEnabled(true);

$port = $kernel->getNetworkPort() instanceof \pocketmine\adapter\driven\network\Protocol84NetworkAdapter
    ? $kernel->getNetworkPort()->getBindPort()
    : 'unknown';
echo '[Khronos] Listening on UDP ' . $port . PHP_EOL;
echo '[Khronos] Server started (PocketMine-MP.phar) - waiting for 0.15.10 clients (protocol 84)' . PHP_EOL;

$kernel->run();
exit(0);
__HALT_COMPILER();
STUB
);

// Add each file to the phar — must use addFile() to read from the
// filesystem; the ArrayAccess syntax ($phar[$k] = $v) stores $v as content.
$count = 0;
foreach ($files as $internalPath => $filePath) {
    $phar->addFile($filePath, $internalPath);
    $count++;
    if ($count % 100 === 0) {
        echo "  Packed {$count} files...\n";
    }
}
echo "  Packed {$count} files total.\n\n";

// ─── Compression ────────────────────────────────────────────────────
// setCompression() is not available in all PHP builds; skip if missing.
// The phar will use no compression by default — re-compress with gzip
// externally if distribution size matters:
//   gzip -9 PocketMine-MP.phar && mv PocketMine-MP.phar.gz PocketMine-MP.phar
//
// ─── Signature ──────────────────────────────────────────────────────
// SHA-256 signature is the default for Phar::DEFAULT_SIG (SHA1).
// setSignatureAlgorithm() may not be available in all builds.
if (method_exists($phar, 'setCompression')) {
    $phar->setCompression(Phar::GZ);
}
if (method_exists($phar, 'setSignatureAlgorithm')) {
    $phar->setSignatureAlgorithm(Phar::SHA256);
}

// ─── Finalize ───────────────────────────────────────────────────────
$phar->stopBuffering();

$pharSize  = filesize($pharPath);
$elapsed   = microtime(true) - $startTime;

echo "=== Build Complete ===\n";
echo "  Output: {$pharPath}\n";
echo "  Size:   " . number_format($pharSize) . " bytes (" .
     number_format($pharSize / 1024 / 1024, 2) . " MB)\n";
echo "  Files:  {$count}\n";
echo "  Time:   " . number_format($elapsed, 2) . "s\n";
echo "\n";

echo "=== How to run ===\n";
echo "  cd /path/to/server/\n";
echo "  # Make sure these exist alongside PocketMine-MP.phar:\n";
echo "  #   bin/php7/bin/php   (the PHP runtime)\n";
echo "  #   native/*.so        (FFI native acceleration, optional)\n";
echo "  #   worlds/            (world data, created on first run)\n";
echo "  #   plugins/           (plugin directory, created on first run)\n";
echo "\n";
echo "  php -d memory_limit=512M -d extension=ffi -d ffi.enable=1 PocketMine-MP.phar\n";
echo "\n";
echo "Or use the start.sh wrapper (update POCKETMINE_FILE if needed).\n";

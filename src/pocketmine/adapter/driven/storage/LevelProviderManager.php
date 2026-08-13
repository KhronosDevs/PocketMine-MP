<?php

declare(strict_types=1);

namespace pocketmine\adapter\driven\storage;

use pocketmine\port\driven\StoragePort;
use function file_exists;
use function glob;
use function is_dir;
use function rtrim;
use function sprintf;

/**
 * Picks the right storage provider for a world folder - the modern
 * equivalent of the legacy LevelProviderManager's auto-detect.
 *
 * Detection inspects the world folder (worlds/<name>/):
 *   - a db/ subfolder                -> LevelDB (needs the php-leveldb
 *                                        extension; throws a clear error)
 *   - region/*.mca region files      -> Anvil (also reads legacy pre-NBT
 *                                        Khronos saves in .mca containers)
 *   - region/*.mcr region files      -> McRegion (128-height classic)
 *   - nothing                        -> fresh world, default Anvil
 *
 * A folder that carries both .mca and .mcr files is treated as Anvil (the
 * successor format). Real Anvil/McRegion saves are detected purely from the
 * region files on disk, so a pre-existing world just works.
 */
final class LevelProviderManager {
    public const FORMAT_ANVIL = 'anvil';
    public const FORMAT_MCREGION = 'mcregion';
    public const FORMAT_LEVELDB = 'leveldb';
    public const FORMAT_NONE = 'none';

    /** The data path that stores world folders (default 'worlds/'). */
    public const DEFAULT_DATA_PATH = 'worlds/';

    /**
     * Detect the storage format of an existing world folder without touching
     * its contents.
     */
    public static function detect(string $dataPath, string $levelName): string {
        $folder = self::worldFolder($dataPath, $levelName);

        if (is_dir($folder . 'db')) {
            return self::FORMAT_LEVELDB;
        }

        $region = $folder . 'region/';
        if (is_dir($region)) {
            $hasMca = self::globAny($region . '*.mca');
            if ($hasMca) {
                return self::FORMAT_ANVIL;
            }
            if (self::globAny($region . '*.mcr')) {
                return self::FORMAT_MCREGION;
            }
        }

        return self::FORMAT_NONE;
    }

    /**
     * Build the StoragePort for a world folder, auto-detecting its format.
     *
     * @throws \RuntimeException when the folder is a LevelDB world (the
     *                           php-leveldb extension is not available)
     */
    public static function create(string $dataPath, string $levelName): StoragePort {
        return match (self::detect($dataPath, $levelName)) {
            self::FORMAT_MCREGION => new McRegionStorageAdapter($dataPath, $levelName),
            self::FORMAT_LEVELDB => throw new \RuntimeException(sprintf(
                "World '%s' uses the LevelDB format, which requires the php-leveldb extension (not available in this build). " .
                'Convert the world to Anvil/McRegion with a supported tool, or delete it and let the server generate a fresh one.',
                $levelName
            )),
            default => new AnvilStorageAdapter($dataPath, $levelName), // anvil + fresh worlds
        };
    }

    private static function worldFolder(string $dataPath, string $levelName): string {
        return rtrim($dataPath, '/\\') . DIRECTORY_SEPARATOR . $levelName . DIRECTORY_SEPARATOR;
    }

    private static function globAny(string $pattern): bool {
        $matches = glob($pattern);
        return $matches !== false && $matches !== [];
    }
}

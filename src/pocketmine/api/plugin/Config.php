<?php

declare(strict_types=1);

namespace pocketmine\api\plugin;

class Config
{
    /** Format-type constants kept for PocketMine-MP API parity. Plugins
     *  written against the old API pass these as the second constructor
     *  argument (new Config($file, Config::YAML, $defaults)); the format is
     *  always YAML here, so the values only need to exist and be harmless. */
    public const DETECT = 0;
    public const PROPERTIES = 1;
    public const CNF = self::PROPERTIES;
    public const JSON = 2;
    public const YAML = 3;
    public const ENUM = 4;

    private string $file;
    private array $defaults = [];
    private array $data = [];
    private bool $modified = false;

    public function __construct(string $file, mixed $defaults = [], array $legacyDefaults = [])
    {
        // Legacy signature compat: old-API plugins call either
        //   new Config($file, $defaultsArray)
        // or the PocketMine shape
        //   new Config($file, Config::YAML, $defaultsArray)
        // When the second argument is a format constant (int), the real
        // defaults are the third argument. Extra args beyond that (the old
        // &$correct out-param) are ignored.
        if (!is_array($defaults)) {
            $defaults = $legacyDefaults;
        }
        $this->file = $file;
        $this->defaults = $defaults;
        $this->data = $defaults;

        if (file_exists($file)) {
            $content = file_get_contents($file);
            if ($content !== false) {
                $disk = \yaml_parse($content) ?? [];
                $this->data = array_merge($defaults, $disk);
                // New default keys added in a later version are missing on
                // disk: mark modified so the next save() writes them out.
                // Previously save() early-returned success here and the new
                // keys never reached the file.
                if ($this->data !== $disk) {
                    $this->modified = true;
                }
            }
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->data;

        foreach ($keys as $key) {
            if (!is_array($value) || !isset($value[$key])) {
                return $default;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * PocketMine-MP API parity: dot-path getter ("a.b.c" nests into the
     * data array). Identical behavior to get() — which has always walked
     * dot paths — but plugins written against the old API call these
     * canonical names.
     */
    public function getNested(string $key, mixed $default = null): mixed
    {
        return $this->get($key, $default);
    }

    /**
     * PocketMine-MP API parity: dot-path setter. Identical behavior to
     * set() (which already creates intermediate arrays).
     */
    public function setNested(string $key, mixed $value): void
    {
        $this->set($key, $value);
    }

    public function set(string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $last = array_pop($keys);
        $ref = &$this->data;

        foreach ($keys as $key) {
            if (!isset($ref[$key]) || !is_array($ref[$key])) {
                $ref[$key] = [];
            }
            $ref = &$ref[$key];
        }

        $ref[$last] = $value;
        $this->modified = true;
    }

    public function getAll(): array
    {
        return $this->data;
    }

    public function setAll(array $data): void
    {
        $this->data = $data;
        $this->modified = true;
    }

    public function remove(string $key): void
    {
        $keys = explode('.', $key);
        $last = array_pop($keys);
        $ref = &$this->data;

        foreach ($keys as $key) {
            if (!is_array($ref) || !isset($ref[$key])) {
                return;
            }
            $ref = &$ref[$key];
        }

        unset($ref[$last]);
        $this->modified = true;
    }

    public function exists(string $key): bool
    {
        $keys = explode('.', $key);
        $value = $this->data;

        foreach ($keys as $key) {
            if (!is_array($value) || !isset($value[$key])) {
                return false;
            }
            $value = $value[$key];
        }

        return true;
    }

    public function getKeys(bool $recursive = false): array
    {
        if (!$recursive) {
            return array_keys($this->data);
        }

        $keys = [];
        $this->collectKeys($this->data, '', $keys);
        return $keys;
    }

    private function collectKeys(array $array, string $prefix, array &$keys): void
    {
        foreach ($array as $key => $value) {
            $fullKey = $prefix . $key;
            $keys[] = $fullKey;
            if (is_array($value)) {
                $this->collectKeys($value, $fullKey . '.', $keys);
            }
        }
    }

    public function save(): bool
    {
        if (!$this->modified) {
            return true;
        }

        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $yaml = yaml_emit($this->data, YAML_UTF8_ENCODING);
        $result = file_put_contents($this->file, $yaml);

        if ($result !== false) {
            $this->modified = false;
            return true;
        }

        return false;
    }

    public function reload(): void
    {
        // Defaults first, then disk values on top — same merge as the
        // constructor, so keys that exist only as defaults survive reloads.
        $this->data = $this->defaults;
        if (file_exists($this->file)) {
            $content = file_get_contents($this->file);
            if ($content !== false) {
                $disk = \yaml_parse($content) ?? [];
                $this->data = array_merge($this->defaults, $disk);
                if ($this->data !== $disk) {
                    $this->modified = true;
                }
            }
        } else {
            // No file yet: everything came from defaults.
            $this->modified = true;
        }
    }

    public function isModified(): bool
    {
        return $this->modified;
    }
}


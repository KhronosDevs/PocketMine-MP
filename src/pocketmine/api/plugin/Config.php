<?php

declare(strict_types=1);

namespace pocketmine\api\plugin;

class Config
{
    private string $file;
    private array $defaults = [];
    private array $data = [];
    private bool $modified = false;

    public function __construct(string $file, array $defaults = [])
    {
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


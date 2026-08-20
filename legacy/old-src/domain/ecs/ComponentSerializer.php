<?php

declare(strict_types=1);

namespace pocketmine\domain\ecs;

use pocketmine\domain\component\Component;

/**
 * Component serialization helpers for storage/network snapshots.
 */
final class ComponentSerializer {
    /** @return array<string, mixed> */
    public static function serialize(Component $component): array {
        $data = ['__type' => get_class($component)];
        foreach ((new \ReflectionObject($component))->getProperties() as $prop) {
            if ($prop->isStatic()) continue;
            $prop->setAccessible(true);
            $value = $prop->getValue($component);
            if ($value instanceof Component) {
                $data[$prop->getName()] = self::serialize($value);
            } elseif (is_array($value)) {
                $data[$prop->getName()] = array_map(
                    fn($v) => $v instanceof Component ? self::serialize($v) : $v,
                    $value
                );
            } else {
                $data[$prop->getName()] = $value;
            }
        }
        return $data;
    }

    public static function deserialize(array $data): ?Component {
        $type = $data['__type'] ?? null;
        if (!$type || !class_exists($type)) {
            return null;
        }

        $component = new $type();
        foreach ($data as $key => $value) {
            if ($key === '__type') continue;
            if (property_exists($component, $key)) {
                $component->$key = $value;
            }
        }
        return $component;
    }

    /** @param array<string, Component> $components */
    public static function serializeAll(array $components): array {
        $result = [];
        foreach ($components as $type => $component) {
            $result[$type] = self::serialize($component);
        }
        return $result;
    }

    /** @param array<string, array> $data */
    public static function deserializeAll(array $data): array {
        $result = [];
        foreach ($data as $type => $componentData) {
            $component = self::deserialize($componentData);
            if ($component) {
                $result[$type] = $component;
            }
        }
        return $result;
    }
}
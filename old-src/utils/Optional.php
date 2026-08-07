<?php

declare(strict_types=1);

namespace pocketmine\utils;

final class Optional {

    private readonly mixed $value;
    private readonly bool $empty;

    private function __construct(mixed $value, bool $empty) {
        $this->value = $value;
        $this->empty = $empty;
    }

    public function get(): mixed {
        return $this->value;
    }

    public function or(mixed $value): mixed {
        return $this->isEmpty() ? $value : $this->value;
    }

    public function isEmpty(): bool {
        return $this->empty;
    }

    public static function empty(): Optional {
        return new Optional(null, true);
    }

    public static function of(mixed $value): Optional {
        return new Optional($value, false);
    }

}

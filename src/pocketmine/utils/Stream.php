<?php



namespace pocketmine\utils;

use Closure;

/**
 * Represents a stream of elements providing functional-style operations on arrays.
 */
final class Stream {

    /** @var array */
    private $array;

    private function __construct(array &$array) {
        $this->array = $array;
    }

    public function forEach(Closure $callback): self {
        foreach ($this->array as $key => $value) {
            $callback($value, $key);
        }

        return $this;
    }

    public function filter(Closure $condition): self {
        foreach ($this->array as $key => $value) {
            if ($condition($value, $key)) {
                continue;
            }

            unset($this->array[$key]);
        }

        return $this;
    }

    public function map(Closure $transform): self {
        foreach ($this->array as $key => $value) {
            $this->array[$key] = $transform($value, $key);
        }

        return $this;
    }

    public function mapKeys(Closure $transform): self {
        foreach ($this->array as $key => $value) {
            $newKey = $transform($key, $value);

            unset($this->array[$key]);

            $this->array[$newKey] = $value;
        }

        return $this;
    }

    public function limit(int $elements): self {
        array_splice($this->array, $elements);

        return $this;
    }

    public function skip(int $elements): self {
        array_splice($this->array, 0, $elements);

        return $this;
    }

    public function values(): self {
        $this->array = array_values($this->array);

        return $this;
    }

    public function keys(): self {
        $this->array = array_keys($this->array);

        return $this;
    }

    public function first(): Optional {
        if ($this->size() !== 0) {
            $firstKey = array_keys($this->array)[0];

            return Optional::of($this->array[$firstKey]);
        }

        return Optional::empty();
    }

    public function last(): Optional {
        if ($this->size() != 0) {
			$lastKey = array_pop(array_keys($this->array));

			return Optional::of($this->array[$lastKey]);
		}

		return Optional::empty();
    }

    public function size(): int {
        return count($this->array);
    }

    public function toArray(): array {
        return $this->array;
    }

    public static function of(array $array) {
        return new self($array);
    }

    public static function ofRef(array &$array) {
        return new self($array);
    }

}

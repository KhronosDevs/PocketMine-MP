<?php

namespace pocketmine\utils;

final class Optional {
    
    /** @var mixed */
	private $value;

    /** @var bool */
	private $empty;

	private function __construct($value, bool $empty) {
		$this->value = $value;
		$this->empty = $empty;
	}

	public function get() {
		return $this->value;
	}

	public function or($value) {
		return $this->isEmpty() ? $value : $this->value;
	}

	public function isEmpty(): bool {
		return $this->empty;
	}
    
	public static function empty(): Optional {
		return new Optional(null, true);
	}

	public static function of($value): Optional {
		return new Optional($value, false);
	}

}
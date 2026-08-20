<?php



declare(strict_types=1);

namespace pocketmine\utils;

trait SingletonTrait {

	private static ?self $instance = null;

	private static function make(): self {
		return new self();
	}

	public static function getInstance(): self {
		if(self::$instance === null) {
			self::$instance = self::make();
		}

		return self::$instance;
	}

	public static function setInstance(self $instance): void {
		self::$instance = $instance;
	}

	public static function reset(): void {
		self::$instance = null;
	}

}

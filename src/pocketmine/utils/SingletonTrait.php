<?php

declare(strict_types=1);

namespace pocketmine\utils;

trait SingletonTrait {

	/** @var self|null */
	private static $instance = null;

	/**
	 * @return self
	 */
	private static function make() {
		return new self();
	}

	/**
	 * @return self
	 */
	public static function getInstance() {
		if(self::$instance === null) {
			self::$instance = self::make();
		}

		return self::$instance;
	}

	public static function setInstance($instance) {
		self::$instance = $instance;
	}

	public static function reset() {
		self::$instance = null;
	}

}

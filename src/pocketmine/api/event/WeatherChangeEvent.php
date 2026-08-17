<?php

declare(strict_types=1);

namespace pocketmine\api\event;

use pocketmine\api\world\World;

/**
 * Fires when a world's weather changes (clear <-> rain/thunder, /weather).
 * Cancelling reverts the world to its previous weather.
 */
class WeatherChangeEvent extends CancellableEvent {
    public function __construct(
        public readonly World $world,
        public int $weather,
    ) {}

    public function getWorld(): World {
        return $this->world;
    }

    /** @return int legacy weather id (0 clear, 1 rain, 2 rain+thunder, ...) */
    public function getWeather(): int {
        return $this->weather;
    }

    public function setWeather(int $weather): void {
        $this->weather = $weather;
    }

    public function isRaining(): bool {
        $weather = $this->weather;
        return $weather === 1 || $weather === 2 || $weather === 3;
    }

    public function isThundering(): bool {
        return $this->weather === 2 || $this->weather === 3;
    }
}

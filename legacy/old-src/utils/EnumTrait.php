<?php



declare(strict_types=1);

namespace pocketmine\utils;

trait EnumTrait {

    protected string $enumValue;

    private function __construct(string $enumValue) {
        $this->enumValue = $enumValue;
    }

    public static function __callStatic(string $methodName, array $arguments) {
        $className = get_called_class();

        return new $className($methodName);
    }

    public function __toString(): string {
        return $this->enumValue;
    }

}

<?php



namespace pocketmine\utils;

trait EnumTrait {

    protected $enumValue;

    private function __construct($enumValue) {
        $this->enumValue = $enumValue;
    }

    public static function __callStatic($methodName, $arguments) {
        $className = get_called_class();

        return new $className($methodName);
    }

    public function __toString() {
        return $this->enumValue;
    }

}

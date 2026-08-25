<?php

declare(strict_types=1);

namespace Mata\Config;

use InvalidArgumentException;

/** Shared primitive validation for configuration factories. */
final class ConfigValueValidator
{
    public static function integer(
        mixed $value,
        string $path,
        ?int $minimum = null,
        ?int $maximum = null,
    ): int {
        if (!is_int($value)) {
            throw new InvalidArgumentException("{$path} must be an integer.");
        }

        return self::boundedInteger($value, $path, $minimum, $maximum);
    }

    public static function integerString(
        mixed $value,
        string $path,
        ?int $minimum = null,
        ?int $maximum = null,
    ): int {
        if (!is_string($value) || preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw new InvalidArgumentException("{$path} must be an integer.");
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false) {
            throw new InvalidArgumentException("{$path} must be an integer within the supported range.");
        }

        return self::boundedInteger($integer, $path, $minimum, $maximum);
    }

    public static function decimalString(
        mixed $value,
        string $path,
        ?float $minimum = null,
        ?float $maximum = null,
    ): float {
        if (!is_string($value) || preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) !== 1) {
            throw new InvalidArgumentException("{$path} must be a decimal number.");
        }

        $number = filter_var($value, FILTER_VALIDATE_FLOAT);
        if ($number === false || !is_finite($number)) {
            throw new InvalidArgumentException("{$path} must be a decimal number within the supported range.");
        }
        if ($minimum !== null && $number < $minimum) {
            throw new InvalidArgumentException("{$path} must be at least {$minimum}.");
        }
        if ($maximum !== null && $number > $maximum) {
            throw new InvalidArgumentException("{$path} must be at most {$maximum}.");
        }

        return $number;
    }

    private static function boundedInteger(
        int $value,
        string $path,
        ?int $minimum,
        ?int $maximum,
    ): int {
        if ($minimum !== null && $value < $minimum) {
            throw new InvalidArgumentException("{$path} must be at least {$minimum}.");
        }
        if ($maximum !== null && $value > $maximum) {
            throw new InvalidArgumentException("{$path} must be at most {$maximum}.");
        }

        return $value;
    }
}

<?php

declare(strict_types=1);

namespace Mata\Config;

use InvalidArgumentException;

/** Shared primitive validation for configuration factories. */
final class ConfigValueValidator
{
    /**
     * A decoded JSON object; an empty one decodes to the same [] as an empty list.
     *
     * @phpstan-assert-if-true array<array-key, mixed> $value
     */
    public static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    /** Lowercase letters, digits, '-' and '_', starting with a letter or digit: safe in URLs, file names and SQL. */
    public static function isSlug(string $value, ?int $maxLength = null): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $value) === 1
            && ($maxLength === null || strlen($value) <= $maxLength);
    }

    /** Upper-case letters, digits and '_', starting with a letter. */
    public static function isEnvName(string $value): bool
    {
        return preg_match('/^[A-Z][A-Z0-9_]*$/D', $value) === 1;
    }

    /** @return array<array-key, mixed> */
    public static function object(mixed $value, string $path): array
    {
        if (!self::isObject($value)) {
            throw new InvalidArgumentException("{$path} must be an object.");
        }

        return $value;
    }

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

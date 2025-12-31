<?php

declare(strict_types=1);

namespace Watcher\Utils;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * Utility class for safe type conversions with null handling.
 *
 * Provides consistent type conversion across the application,
 * handling edge cases like empty strings, numeric strings, and null values.
 */
final class TypeConverter
{
    /**
     * Private constructor prevents instantiation of this utility class.
     *
     * This class is designed to be used statically only, as it contains
     * no state and all methods are pure functions.
     */
    private function __construct() {}

    /**
     * Convert mixed value to integer with null safety.
     *
     * @param mixed $value Value to convert
     *
     * @return null|int Integer value or null if conversion fails
     */
    public static function toInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        if (is_numeric($value)) {
            return (int) round((float) $value);
        }

        return null;
    }

    /**
     * Convert mixed value to float with null safety.
     *
     * @param mixed $value     Value to convert
     * @param int   $precision Decimal precision for rounding (default: 2)
     *
     * @return null|float Float value or null if conversion fails
     */
    public static function toFloat(mixed $value, int $precision = 2): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_float($value) || is_int($value)) {
            return round((float) $value, $precision);
        }

        if (is_numeric($value)) {
            return round((float) $value, $precision);
        }

        return null;
    }

    /**
     * Convert mixed value to string with null safety and trimming.
     *
     * @param mixed $value Value to convert
     *
     * @return null|string Trimmed string value or null if empty/invalid
     */
    public static function toString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        if (is_scalar($value)) {
            return trim((string) $value) ?: null;
        }

        return null;
    }

    /**
     * Convert mixed value to DateTimeImmutable with null safety.
     *
     * Supports:
     * - DateTimeImmutable instances (returned as-is)
     * - DateTimeInterface instances (converted to DateTimeImmutable)
     * - String timestamps (ISO8601, etc.)
     * - Numeric Unix timestamps (auto-detects milliseconds)
     *
     * @param mixed $value Value to convert
     *
     * @return null|DateTimeImmutable DateTime object or null if conversion fails
     */
    public static function toDateTime(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (is_string($value)) {
            try {
                return new DateTimeImmutable($value);
            } catch (Throwable) {
                return null;
            }
        }

        if (is_numeric($value)) {
            $numeric = (float) $value;
            // Convert milliseconds to seconds
            if ($numeric > 9999999999) {
                $numeric /= 1000;
            }

            $dateTime = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $numeric));
            if ($dateTime instanceof DateTimeImmutable) {
                return $dateTime->setTimezone(new DateTimeZone(date_default_timezone_get()));
            }
        }

        return null;
    }

    /**
     * Convert mixed value to boolean with null safety.
     *
     * Recognizes common boolean representations:
     * - true: 1, '1', 'true', 'yes', 'on', 'enabled'
     * - false: 0, '0', 'false', 'no', 'off', 'disabled'
     *
     * @param mixed $value Value to convert
     *
     * @return null|bool Boolean value or null if not recognizable
     */
    public static function toBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return null;
            }

            if (in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off', 'disabled'], true)) {
                return false;
            }
        }

        return null;
    }
}

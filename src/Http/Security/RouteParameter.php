<?php

declare(strict_types=1);

namespace Mata\Http\Security;

use InvalidArgumentException;

/**
 * Validates route path segments (e.g. {server}, {app}) against a safe charset.
 *
 * Guards against path traversal and injection through user-controlled URL
 * segments before they reach controllers or downstream services.
 */
final class RouteParameter
{
    /** Safe path-segment charset: letters, digits, underscore, hyphen, dot. */
    private const SAFE_PATTERN = '/^[a-zA-Z0-9_\-.]+$/';

    /**
     * Throw if the value contains anything outside the safe charset.
     *
     * @throws InvalidArgumentException when validation fails
     */
    public static function assertSafe(string $value, string $errorMessage): void
    {
        if (preg_match(self::SAFE_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException($errorMessage);
        }
    }

    /**
     * Validate several segments against the same error message.
     *
     * @param array<int,string> $values
     *
     * @throws InvalidArgumentException on the first value that fails
     */
    public static function assertAllSafe(array $values, string $errorMessage): void
    {
        foreach ($values as $value) {
            self::assertSafe($value, $errorMessage);
        }
    }
}

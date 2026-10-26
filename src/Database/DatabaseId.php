<?php

declare(strict_types=1);

namespace Mata\Database;

/**
 * Format check for auto-increment IDs arriving as strings from routes, request bodies or the CLI.
 */
final class DatabaseId
{
    private const PATTERN = '/^[1-9][0-9]{0,18}$/';

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }
}

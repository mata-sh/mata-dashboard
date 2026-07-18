<?php

declare(strict_types=1);

namespace Mata\Config;

final class Env
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? (getenv($key) !== false ? getenv($key) : $default);

        return $value === '' ? $default : $value;
    }
}

<?php

declare(strict_types=1);

namespace Mata\Database;

final class SqlQueryClassifier
{
    public static function leadingKeyword(string $sql): string
    {
        if (preg_match('/^\s*([a-z]+)/i', $sql, $matches) !== 1) {
            return '';
        }

        return strtoupper($matches[1]);
    }
}

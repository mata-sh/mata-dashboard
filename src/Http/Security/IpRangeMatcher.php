<?php

declare(strict_types=1);

namespace Mata\Http\Security;

final class IpRangeMatcher
{
    /** @param list<string> $ranges */
    public static function matchesAny(?string $ip, array $ranges): bool
    {
        if ($ip === null || inet_pton($ip) === false) {
            return false;
        }

        foreach ($ranges as $range) {
            if (self::matches($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    public static function isValidRange(string $range): bool
    {
        [$network, $prefix] = self::splitRange($range);
        $packedNetwork = inet_pton($network);
        if ($packedNetwork === false) {
            return false;
        }

        if ($prefix === null) {
            return true;
        }

        if ($prefix === '' || preg_match('/^\d+$/D', $prefix) !== 1) {
            return false;
        }

        return (int) $prefix <= strlen($packedNetwork) * 8;
    }

    private static function matches(string $ip, string $range): bool
    {
        if (!self::isValidRange($range)) {
            return false;
        }

        [$network, $prefix] = self::splitRange($range);
        $packedIp = inet_pton($ip);
        $packedNetwork = inet_pton($network);
        if ($packedIp === false || $packedNetwork === false || strlen($packedIp) !== strlen($packedNetwork)) {
            return false;
        }

        if ($prefix === null) {
            return $packedIp === $packedNetwork;
        }

        $prefixLength = (int) $prefix;
        $wholeBytes = intdiv($prefixLength, 8);
        if (substr($packedIp, 0, $wholeBytes) !== substr($packedNetwork, 0, $wholeBytes)) {
            return false;
        }

        $remainingBits = $prefixLength % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($packedIp[$wholeBytes]) & $mask) === (ord($packedNetwork[$wholeBytes]) & $mask);
    }

    /** @return array{string,?string} */
    private static function splitRange(string $range): array
    {
        $parts = explode('/', $range, 2);

        return [$parts[0], $parts[1] ?? null];
    }
}

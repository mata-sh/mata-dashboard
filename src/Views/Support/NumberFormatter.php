<?php

declare(strict_types=1);

namespace Mata\Views\Support;

/** Formats numeric values for display. */
final class NumberFormatter
{
    /** Format numbers with compact unit suffixes. */
    public function formatCompact(mixed $number): string
    {
        if (!is_numeric($number)) {
            return '0';
        }

        $number = (float) $number;
        $units = ['', 'k', 'm', 'b', 't'];
        $unitIndex = 0;

        while (abs($number) >= 1000 && $unitIndex < count($units) - 1) {
            $number /= 1000;
            ++$unitIndex;
        }

        if ($unitIndex === 0) {
            return (string) (int) $number;
        }

        $decimals = abs($number) < 10 ? 1 : 0;
        $factor = 10 ** $decimals;
        $number = $number >= 0
            ? floor($number * $factor) / $factor
            : ceil($number * $factor) / $factor;
        $formatted = number_format($number, $decimals, '.', '');

        if ($decimals > 0) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted . $units[$unitIndex];
    }
}

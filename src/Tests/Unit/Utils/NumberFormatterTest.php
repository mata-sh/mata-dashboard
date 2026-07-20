<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Utils;

use Mata\Tests\TestCase;
use Mata\Utils\NumberFormatter;

/**
 * @covers \Mata\Utils\NumberFormatter
 *
 * @internal
 */
final class NumberFormatterTest extends TestCase
{
    public function testItFormatsCompactNumbers(): void
    {
        $formatter = new NumberFormatter();
        $testCases = [
            [999, '999'],
            [1250, '1.2k'],
            [238512, '238k'],
            [240000, '240k'],
            [2500000, '2.5m'],
            ['abc', '0'],
        ];

        foreach ($testCases as [$number, $expected]) {
            $this->assertSame($expected, $formatter->formatCompact($number));
        }
    }
}

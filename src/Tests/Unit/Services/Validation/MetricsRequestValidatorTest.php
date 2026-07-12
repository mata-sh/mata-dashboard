<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services\Validation;

use Mata\Services\Validation\MetricsRequestValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Services\Validation\MetricsRequestValidator
 *
 * @internal
 */
final class MetricsRequestValidatorTest extends TestCase
{
    #[DataProvider('automaticGranularityRanges')]
    public function testResolvesAutomaticGranularityAtRangeBoundaries(string $range, string $expected): void
    {
        $validator = new MetricsRequestValidator([], ['auto', 'raw', 'hourly', 'daily']);

        self::assertSame($expected, $validator->resolveGranularity($range, 'auto'));
    }

    public static function automaticGranularityRanges(): array
    {
        return [
            'six hours is raw' => ['6h', 'raw'],
            'seven hours is hourly' => ['7h', 'hourly'],
            'seven days is hourly' => ['7d', 'hourly'],
            'eight days is daily' => ['8d', 'daily'],
            'one week is hourly' => ['1w', 'hourly'],
            'two weeks is daily' => ['2w', 'daily'],
        ];
    }
}

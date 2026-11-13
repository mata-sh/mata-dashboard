<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services\ServerMetrics;

use Mata\Services\ServerMetrics\MetricQueryPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Services\ServerMetrics\MetricQueryPolicy
 *
 * @internal
 */
final class MetricQueryPolicyTest extends TestCase
{
    #[DataProvider('automaticGranularityRanges')]
    public function testResolvesAutomaticGranularityAtRangeBoundaries(string $range, string $expected): void
    {
        self::assertSame($expected, (new MetricQueryPolicy())->resolveGranularity($range, 'auto'));
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

    public function testRejectsRangesThatDateIntervalCannotRepresent(): void
    {
        self::assertNull((new MetricQueryPolicy())->parseRange('999999999999999999999y'));
    }

    public function testNormalizesMetricInput(): void
    {
        self::assertSame('ram_percent', (new MetricQueryPolicy())->normalizeMetric(' RAM-Percent '));
    }
}

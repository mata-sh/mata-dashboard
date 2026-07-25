<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services\Metrics;

use Mata\Services\Metrics\MetricCatalog;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Services\Metrics\MetricCatalog
 *
 * @internal
 */
final class MetricCatalogTest extends TestCase
{
    public function testExposesEveryQueryableMetric(): void
    {
        self::assertSame([
            'cpu',
            'ram_percent',
            'ram_used',
            'disk_percent',
            'disk_used',
            'file_count',
            'process_count',
        ], (new MetricCatalog())->metrics());
    }

    public function testExposesRequestableGranularities(): void
    {
        self::assertSame(['auto', 'raw', 'hourly', 'daily'], (new MetricCatalog())->granularities());
    }

    public function testSupportsOnlyCatalogedMetrics(): void
    {
        $catalog = new MetricCatalog();

        self::assertTrue($catalog->supports('cpu'));
        self::assertTrue($catalog->supports('process_count'));
        self::assertFalse($catalog->supports('swap_used'));
        self::assertFalse($catalog->supports(''));
    }

    public function testSupportsExpectsNormalizedKeys(): void
    {
        $catalog = new MetricCatalog();

        self::assertFalse($catalog->supports('CPU'));
        self::assertFalse($catalog->supports('ram-percent'));
    }

    public function testAllowsOnlyCatalogedGranularities(): void
    {
        $catalog = new MetricCatalog();

        self::assertTrue($catalog->allowsGranularity('auto'));
        self::assertTrue($catalog->allowsGranularity('daily'));
        self::assertFalse($catalog->allowsGranularity('weekly'));
        self::assertFalse($catalog->allowsGranularity(''));
    }
}

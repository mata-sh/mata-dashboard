<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use DateTimeImmutable;
use Mata\Services\ServerMetrics\ServerMetricsQueryInterface;
use Mata\Services\View\MetricsViewBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \Mata\Services\View\MetricsViewBuilder
 *
 * @internal
 */
final class MetricsViewBuilderTest extends TestCase
{
    public function testAllTimeRangeStartsAtTheUtcEpoch(): void
    {
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('America/Los_Angeles');
        $start = null;

        try {
            $query = $this->createMock(ServerMetricsQueryInterface::class);
            $query->expects($this->once())
                ->method('getRawMetrics')
                ->willReturnCallback(
                    static function (
                        string $serverName,
                        DateTimeImmutable $from,
                        DateTimeImmutable $to
                    ) use (&$start): array {
                        $start = $from;

                        return [];
                    }
                );

            (new MetricsViewBuilder($query, new NullLogger()))
                ->buildServerMetricTimeSeries('server-a', 'cpu', 'all', 'raw');

            self::assertInstanceOf(DateTimeImmutable::class, $start);
            self::assertSame('1970-01-01T00:00:00+00:00', $start->format(DATE_ATOM));
            self::assertSame('UTC', $start->getTimezone()->getName());
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }
}

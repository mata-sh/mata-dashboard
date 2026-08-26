<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services\ServerMetrics;

use Mata\Services\ServerMetrics\MetricCatalog;
use Mata\Services\ServerMetrics\MetricQueryPolicy;
use Mata\Services\ServerMetrics\MetricsRequestValidator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Services\ServerMetrics\MetricsRequestValidator
 *
 * @internal
 */
final class MetricsRequestValidatorTest extends TestCase
{
    public function testValidatesAndNormalizesHttpQueryInputs(): void
    {
        $validator = new MetricsRequestValidator(new MetricCatalog(), new MetricQueryPolicy());

        $result = $validator->validate('server', ' RAM-Percent ', '24h', 'auto', ['server' => []]);

        self::assertTrue($result->isValid());
        self::assertSame('ram_percent', $result->normalizedMetric);
    }

    public function testReportsInvalidHttpQueryInputs(): void
    {
        $validator = new MetricsRequestValidator(new MetricCatalog(), new MetricQueryPolicy());

        $result = $validator->validate('unknown', 'swap', 'forever', 'weekly', ['server' => []]);

        self::assertSame(['server', 'metric', 'granularity', 'range'], array_keys($result->errors));
    }
}

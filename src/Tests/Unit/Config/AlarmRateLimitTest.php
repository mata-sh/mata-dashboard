<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config;

use InvalidArgumentException;
use Mata\Config\Monitoring\Section\AlarmRateLimit;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \Mata\Config\Monitoring\Section\AlarmRateLimit
 *
 * @internal
 */
final class AlarmRateLimitTest extends TestCase
{
    public function testDefaultReturnsTheApplicationTriple(): void
    {
        $this->assertSame(['per_minute' => 20, 'per_hour' => 120, 'per_day' => 500], AlarmRateLimit::default()->toArray());
    }

    public function testUsesTheProvidedTripleAsFallbackForMissingValues(): void
    {
        $fallback = new AlarmRateLimit(1, 2, 3);

        $limit = AlarmRateLimit::fromArray(['per_hour' => 4], $fallback);

        $this->assertSame(['per_minute' => 1, 'per_hour' => 4, 'per_day' => 3], $limit->toArray());
    }

    public function testIsReadonly(): void
    {
        $this->assertTrue((new ReflectionClass(AlarmRateLimit::class))->isReadOnly());
    }

    public function testRejectsNegativeLimitValues(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AlarmRateLimit(-1, 0, 0);
    }
}

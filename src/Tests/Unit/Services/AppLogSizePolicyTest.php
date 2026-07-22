<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use InvalidArgumentException;
use Mata\Services\Monitor\Dto\AppLogSizePolicy;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Services\Monitor\Dto\AppLogSizePolicy
 *
 * @internal
 */
final class AppLogSizePolicyTest extends TestCase
{
    public function testHonorsServerAndApplicationSettings(): void
    {
        $policy = AppLogSizePolicy::fromServerConfig([]);

        $this->assertTrue($policy->isEnabled(['logsize_monitoring_enabled' => true]));
        $this->assertFalse($policy->isEnabled(['logsize_monitoring_enabled' => false]));
    }

    public function testRequiresBooleanServerSetting(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AppLogSizePolicy::fromServerConfig(['logsize_monitoring_enabled' => 'false']);
    }
}

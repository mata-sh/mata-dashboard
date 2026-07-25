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

        $this->assertTrue($policy->isEnabled(['monitoring' => ['logsize' => true]]));
        $this->assertFalse($policy->isEnabled(['monitoring' => ['logsize' => false]]));
        $this->assertFalse(
            AppLogSizePolicy::fromServerConfig(['logsize_monitoring_enabled' => false])
                ->isEnabled(['monitoring' => ['logsize' => true]])
        );
    }

    public function testIgnoresUnrelatedApplicationMonitoringSettings(): void
    {
        $policy = AppLogSizePolicy::fromServerConfig([]);

        $this->assertTrue($policy->isEnabled(['monitoring' => ['loganalysis' => false, 'events' => false]]));
    }

    public function testDefaultsToEnabledWhenTheApplicationFlagIsMissing(): void
    {
        $policy = AppLogSizePolicy::fromServerConfig([]);

        $this->assertTrue($policy->isEnabled([]));
        $this->assertTrue($policy->isEnabled(['monitoring' => []]));
    }

    public function testDefaultsToEnabledOnAMalformedApplicationFlag(): void
    {
        $policy = AppLogSizePolicy::fromServerConfig([]);

        $this->assertTrue($policy->isEnabled(['monitoring' => 'off']));
        $this->assertTrue($policy->isEnabled(['monitoring' => ['logsize' => 'false']]));
        $this->assertTrue($policy->isEnabled(['monitoring' => ['logsizes' => false]]));
    }

    public function testRequiresBooleanServerSetting(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AppLogSizePolicy::fromServerConfig(['logsize_monitoring_enabled' => 'false']);
    }
}

<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use InvalidArgumentException;
use Mata\Services\Monitor\Dto\AppFatalErrorPolicy;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Services\Monitor\Dto\AppFatalErrorPolicy
 *
 * @internal
 */
final class AppFatalErrorPolicyTest extends TestCase
{
    public function testHonorsServerAndApplicationSettings(): void
    {
        $enabledServer = AppFatalErrorPolicy::fromServerConfig([]);
        $disabledServer = AppFatalErrorPolicy::fromServerConfig(['error_monitoring_enabled' => false]);

        $this->assertTrue($enabledServer->isEnabled(['monitoring' => ['loganalysis' => true]]));
        $this->assertFalse($enabledServer->isEnabled(['monitoring' => ['loganalysis' => false]]));
        $this->assertFalse($disabledServer->isEnabled(['monitoring' => ['loganalysis' => true]]));
    }

    public function testIgnoresUnrelatedApplicationMonitoringSettings(): void
    {
        $policy = AppFatalErrorPolicy::fromServerConfig([]);

        $this->assertTrue($policy->isEnabled(['monitoring' => ['logsize' => false, 'sessions' => false]]));
    }

    public function testDefaultsToEnabledWhenTheApplicationFlagIsMissing(): void
    {
        $policy = AppFatalErrorPolicy::fromServerConfig([]);

        $this->assertTrue($policy->isEnabled([]));
        $this->assertTrue($policy->isEnabled(['monitoring' => []]));
    }

    public function testDefaultsToEnabledOnAMalformedApplicationFlag(): void
    {
        $policy = AppFatalErrorPolicy::fromServerConfig([]);

        $this->assertTrue($policy->isEnabled(['monitoring' => 'off']));
        $this->assertTrue($policy->isEnabled(['monitoring' => ['loganalysis' => 'false']]));
        $this->assertTrue($policy->isEnabled(['monitoring' => ['loganalysis' => 0]]));
    }

    public function testRequiresBooleanServerSetting(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AppFatalErrorPolicy::fromServerConfig(['error_monitoring_enabled' => 'false']);
    }
}

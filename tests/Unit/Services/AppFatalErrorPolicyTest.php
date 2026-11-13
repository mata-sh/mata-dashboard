<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Config\Monitoring\Section\ServerConfig;
use Mata\Services\Monitor\Dto\AppFatalErrorPolicy;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Services\Monitor\Dto\AppFatalErrorPolicy
 *
 * @internal
 */
final class AppFatalErrorPolicyTest extends TestCase
{
    public function testHonorsServerConfigAndApplicationSettings(): void
    {
        $enabledServer = AppFatalErrorPolicy::fromServerConfig(ServerConfig::fromArray('srv', []));
        $disabledServer = AppFatalErrorPolicy::fromServerConfig(ServerConfig::fromArray('srv', ['error_monitoring_enabled' => false]));

        $this->assertTrue($enabledServer->isEnabled(['monitoring' => ['loganalysis' => true]]));
        $this->assertFalse($enabledServer->isEnabled(['monitoring' => ['loganalysis' => false]]));
        $this->assertFalse($disabledServer->isEnabled(['monitoring' => ['loganalysis' => true]]));
    }

    public function testIgnoresUnrelatedApplicationMonitoringSettings(): void
    {
        $policy = AppFatalErrorPolicy::fromServerConfig(ServerConfig::fromArray('srv', []));

        $this->assertTrue($policy->isEnabled(['monitoring' => ['logsize' => false, 'sessions' => false]]));
    }

    public function testDefaultsToEnabledWhenTheApplicationFlagIsMissing(): void
    {
        $policy = AppFatalErrorPolicy::fromServerConfig(ServerConfig::fromArray('srv', []));

        $this->assertTrue($policy->isEnabled([]));
        $this->assertTrue($policy->isEnabled(['monitoring' => []]));
    }

    public function testDefaultsToEnabledOnAMalformedApplicationFlag(): void
    {
        $policy = AppFatalErrorPolicy::fromServerConfig(ServerConfig::fromArray('srv', []));

        $this->assertTrue($policy->isEnabled(['monitoring' => 'off']));
        $this->assertTrue($policy->isEnabled(['monitoring' => ['loganalysis' => 'false']]));
        $this->assertTrue($policy->isEnabled(['monitoring' => ['loganalysis' => 0]]));
    }
}

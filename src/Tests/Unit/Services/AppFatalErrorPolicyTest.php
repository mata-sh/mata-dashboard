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
        $policy = AppFatalErrorPolicy::fromServerConfig(['error_monitoring_enabled' => false]);

        $this->assertFalse($policy->isEnabled(['error_monitoring_enabled' => true]));
    }

    public function testRequiresBooleanServerSetting(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AppFatalErrorPolicy::fromServerConfig(['error_monitoring_enabled' => 'false']);
    }
}

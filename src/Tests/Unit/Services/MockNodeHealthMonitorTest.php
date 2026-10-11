<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Monitor\Dto\NodeHealthState;
use Mata\Services\Monitor\MockNodeHealthMonitor;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Services\Monitor\MockNodeHealthMonitor
 *
 * @internal
 */
final class MockNodeHealthMonitorTest extends TestCase
{
    public function testEveryNodeIsHealthyWithStagingInMaintenance(): void
    {
        $monitor = new MockNodeHealthMonitor();

        foreach (['production-web', 'database-server', 'development-server', 'backup-server'] as $server) {
            $result = $monitor->checkSynchronously($server)->toArray();
            $this->assertSame(NodeHealthState::Healthy->value, $result['status']);
            $this->assertFalse($result['maintenance_mode']);
        }

        $staging = $monitor->checkSynchronously('staging-server')->toArray();
        $this->assertSame(NodeHealthState::Healthy->value, $staging['status']);
        $this->assertTrue($staging['maintenance_mode']);
    }
}

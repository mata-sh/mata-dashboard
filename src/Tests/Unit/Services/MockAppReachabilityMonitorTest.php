<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Services\Monitor\Dto\EndpointTarget;
use Mata\Services\Monitor\MockAppReachabilityMonitor;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Services\Monitor\MockAppReachabilityMonitor
 *
 * @internal
 */
final class MockAppReachabilityMonitorTest extends TestCase
{
    public function testEveryEndpointIsReachableWithStableLatency(): void
    {
        $monitor = new MockAppReachabilityMonitor();
        $targets = [
            'shop' => new EndpointTarget('shop', 'https://shop.example.com/shop'),
            'erp' => new EndpointTarget('erp', 'https://shop.example.com/erp'),
        ];

        $first = $monitor->checkMultipleEndpoints($targets);
        $second = $monitor->checkMultipleEndpoints($targets);

        foreach ($first as $identifier => $result) {
            $data = $result->toArray();
            $this->assertTrue($data['reachable']);
            $this->assertFalse($data['maintenance_mode']);
            $this->assertSame(200, $data['httpCode']);
            $this->assertSame($data, $second[$identifier]->toArray());
        }
    }
}

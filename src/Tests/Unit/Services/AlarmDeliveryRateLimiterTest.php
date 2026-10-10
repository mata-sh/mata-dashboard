<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Config\Monitoring\Section\AlarmRateLimit;
use Mata\Config\Monitoring\Section\AlarmRateLimitsConfig;
use Mata\Services\Alarm\Delivery\AlarmDeliveryRateLimiter;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Services\Alarm\Delivery\AlarmDeliveryRateLimiter
 *
 * @internal
 */
final class AlarmDeliveryRateLimiterTest extends TestCase
{
    public function testAllowHonoursLimits(): void
    {
        $default = new AlarmRateLimit(perMinute: 2, perHour: 5, perDay: 10);
        $config = new AlarmRateLimitsConfig($default, ['email' => $default], []);
        $limiter = new AlarmDeliveryRateLimiter($config);

        $this->assertTrue($limiter->allow('srv-1', 'email'));
        $limiter->recordSend('srv-1', 'email');
        $this->assertTrue($limiter->allow('srv-1', 'email'));

        $limiter->recordSend('srv-1', 'email');
        $this->assertFalse($limiter->allow('srv-1', 'email'));
    }

    public function testPreloadInitialisesCounters(): void
    {
        $default = new AlarmRateLimit(perMinute: 3, perHour: 5, perDay: 6);
        $config = new AlarmRateLimitsConfig($default, ['email' => $default], []);
        $limiter = new AlarmDeliveryRateLimiter($config);

        $limiter->preload([
            'srv-1' => [
                'email' => ['minute' => 2, 'hour' => 4, 'day' => 5],
            ],
        ]);

        $this->assertTrue($limiter->allow('srv-1', 'email'));
        $limiter->recordSend('srv-1', 'email');
        $this->assertFalse($limiter->allow('srv-1', 'email'));
    }
}

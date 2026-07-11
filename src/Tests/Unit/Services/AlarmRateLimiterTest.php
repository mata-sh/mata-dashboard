<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Config\Dto\AlarmLimitsConfig;
use Mata\Services\Alarm\AlarmRateLimiter;
use Mata\Services\Alarm\Dto\AlarmLimit;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Services\Alarm\AlarmRateLimiter
 *
 * @internal
 */
final class AlarmRateLimiterTest extends TestCase
{
    public function testAllowHonoursLimits(): void
    {
        $default = new AlarmLimit(perMinute: 2, perHour: 5, perDay: 10);
        $config = new AlarmLimitsConfig($default, ['email' => $default]);
        $limiter = new AlarmRateLimiter($config);

        $this->assertTrue($limiter->allow('srv-1', 'email'));
        $limiter->recordSend('srv-1', 'email');
        $this->assertTrue($limiter->allow('srv-1', 'email'));

        $limiter->recordSend('srv-1', 'email');
        $this->assertFalse($limiter->allow('srv-1', 'email'));
    }

    public function testPreloadInitialisesCounters(): void
    {
        $default = new AlarmLimit(perMinute: 3, perHour: 5, perDay: 6);
        $config = new AlarmLimitsConfig($default, ['email' => $default]);
        $limiter = new AlarmRateLimiter($config);

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

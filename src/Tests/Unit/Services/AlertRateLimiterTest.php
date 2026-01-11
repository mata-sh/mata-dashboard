<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Config\Dto\AlertLimitsConfig;
use Mata\Services\Alert\AlertRateLimiter;
use Mata\Services\Alert\Dto\AlertLimit;
use Mata\Tests\TestCase;

/**
 * @covers \Mata\Services\Alert\AlertRateLimiter
 *
 * @internal
 */
final class AlertRateLimiterTest extends TestCase
{
    public function testAllowHonoursLimits(): void
    {
        $default = new AlertLimit(perMinute: 2, perHour: 5, perDay: 10);
        $config = new AlertLimitsConfig($default, ['email' => $default]);
        $limiter = new AlertRateLimiter($config);

        $this->assertTrue($limiter->allow('srv-1', 'email'));
        $limiter->recordSend('srv-1', 'email');
        $this->assertTrue($limiter->allow('srv-1', 'email'));

        $limiter->recordSend('srv-1', 'email');
        $this->assertFalse($limiter->allow('srv-1', 'email'));
    }

    public function testPreloadInitialisesCounters(): void
    {
        $default = new AlertLimit(perMinute: 3, perHour: 5, perDay: 6);
        $config = new AlertLimitsConfig($default, ['email' => $default]);
        $limiter = new AlertRateLimiter($config);

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

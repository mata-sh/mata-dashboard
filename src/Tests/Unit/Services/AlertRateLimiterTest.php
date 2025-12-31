<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Services;

use Watcher\Config\Dto\AlertLimitsConfig;
use Watcher\Services\Foundation\AlertRateLimiter;
use Watcher\Services\Foundation\Dto\AlertLimit;
use Watcher\Tests\TestCase;

/**
 * @covers \Watcher\Services\Foundation\AlertRateLimiter
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

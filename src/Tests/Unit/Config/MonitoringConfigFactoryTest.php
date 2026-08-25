<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Config;

use Mata\Tests\Helpers\MonitoringConfigFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Mata\Tests\Helpers\MonitoringConfigFactory
 *
 * @internal
 */
final class MonitoringConfigFactoryTest extends TestCase
{
    public function testUsesExplicitEmptyAndDefaultSectionStates(): void
    {
        $config = MonitoringConfigFactory::create();

        $this->assertSame([], $config->servers->values);
        $this->assertSame([], $config->ttl->values);
        $this->assertSame(0, $config->dataRetention->metrics['raw_retention_days']);
        $this->assertSame([], $config->intervals->eventMinimumMinutes);
        $this->assertSame(5, $config->probes->timeoutSeconds);
        $this->assertSame(['enabled' => true, 'channels' => ['email']], $config->notificationRules->defaults);
        $this->assertSame([], $config->thresholds->alarmRules);
        $this->assertSame([], $config->menu->overrides);
        $this->assertSame(20, $config->alarmRateLimits->getGlobalDefault()->getPerMinute());
    }
}

<?php

declare(strict_types=1);

namespace Mata\Tests\Unit\Services;

use Mata\Config\Monitoring\Section\AlarmRateLimit;
use Mata\Config\Monitoring\Section\AlarmRateLimitsConfig;
use Mata\Services\Alarm\AlarmCreatorInterface;
use Mata\Services\Alarm\AlarmDirectProcessor;
use Mata\Services\Alarm\AlarmQueueFailureThrottle;
use Mata\Services\Alarm\Dto\Alarm;
use Mata\Services\Alarm\Dto\AlarmLevel;
use Mata\Services\Event\Dto\EventRecordResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \Mata\Services\Alarm\AlarmDirectProcessor
 *
 * @internal
 */
final class AlarmDirectProcessorTest extends TestCase
{
    public function testForcedAlarmQueuesDirectlyWithoutNotificationRulesOrOpa(): void
    {
        $creator = $this->createMock(AlarmCreatorInterface::class);
        $creator->expects($this->once())
            ->method('create')
            ->with(42, ['email'], 10)
            ->willReturn(7);
        $limit = new AlarmRateLimit(1, 1, 1);
        $processor = new AlarmDirectProcessor(
            $creator,
            new AlarmRateLimitsConfig($limit, ['email' => $limit], []),
            new AlarmQueueFailureThrottle(sys_get_temp_dir() . '/mata-direct-' . bin2hex(random_bytes(8))),
            new NullLogger(),
        );

        $processor->process(
            new Alarm('config_failure', 'Configuration failed', level: AlarmLevel::Critical),
            new EventRecordResult(42, null, null, null, null, null),
        );
    }
}

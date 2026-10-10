<?php

declare(strict_types=1);

namespace Mata\Services\Alarm;

use Mata\Config\Monitoring\Section\AlarmRateLimitsConfig;
use Mata\Services\Alarm\Delivery\AlarmDispatchSpacingCalculator;
use Mata\Services\Alarm\Dto\Alarm;
use Mata\Services\Alarm\Dto\AlarmLevel;
use Mata\Services\Event\Dto\EventRecordResult;
use PDOException;
use Psr\Log\LoggerInterface;

/**
 * Emergency path for forced alarms.
 *
 * Direct alarms intentionally bypass notification rules and OPA so configuration
 * and bootstrap failures can still request email delivery.
 */
class AlarmDirectProcessor
{
    public function __construct(
        private AlarmCreatorInterface $alarmCreator,
        private AlarmRateLimitsConfig $limitsConfig,
        private AlarmQueueFailureThrottle $queueFailureThrottle,
        private LoggerInterface $logger
    ) {}

    public function process(Alarm $alarm, ?EventRecordResult $eventRecord = null): void
    {
        $eventId = $eventRecord === null ? 0 : $eventRecord->eventId;
        $serverKey = $this->normalizeServerKey($eventRecord === null ? $alarm->server : $eventRecord->serverKey);
        $priority = $this->resolvePriority($alarm->level);

        if ($eventId > 0) {
            try {
                $this->alarmCreator->create($eventId, $alarm->channels, $priority);

                return;
            } catch (PDOException $exception) {
                $this->logger->warning("Failed to create alarm; using filesystem throttling [error={$exception->getMessage()}]");
            }
        }

        foreach ($alarm->channels as $channel) {
            $this->throttleQueueFailure($serverKey, (string) $channel);
        }
    }

    private function throttleQueueFailure(string $serverKey, string $channel): void
    {
        $channel = strtolower(trim($channel));
        $spacingSeconds = AlarmDispatchSpacingCalculator::calculateSpacingSeconds($this->limitsConfig->resolve($serverKey, $channel));
        if ($spacingSeconds <= 0) {
            $this->logger->warning("Dropping alarm due to unavailable delivery storage and zero spacing limit [channel={$channel}, server={$serverKey}]");

            return;
        }
        if ($this->queueFailureThrottle->tryAcquire($serverKey, $channel, $spacingSeconds)) {
            $this->logger->warning("Alarm delivery storage unavailable; alarm suppressed after acquiring filesystem lock [channel={$channel}, server={$serverKey}, spacing_seconds={$spacingSeconds}]");
        }
    }

    private function resolvePriority(AlarmLevel $level): int
    {
        return match ($level) {
            AlarmLevel::Critical => 10,
            AlarmLevel::Error => 30,
            AlarmLevel::Warning => 60,
            AlarmLevel::Info => 100,
        };
    }

    private function normalizeServerKey(?string $server): string
    {
        $server = strtolower(trim($server ?? ''));

        return $server !== '' ? $server : 'global';
    }

    public function setLimitsConfig(AlarmRateLimitsConfig $limitsConfig): void
    {
        $this->limitsConfig = $limitsConfig;
    }
}

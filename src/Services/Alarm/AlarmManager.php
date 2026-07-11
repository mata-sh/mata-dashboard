<?php

declare(strict_types=1);

namespace Mata\Services\Alarm;

use Mata\Config\Dto\AlarmLimitsConfig;
use Mata\Models\Dto\EventRecordResult;
use Mata\Services\Alarm\Dto\Alarm;
use Mata\Services\Alarm\Dto\AlarmLevel;
use Mata\Utils\AlarmLimitHelper;
use Mata\Utils\ChannelLockManager;
use PDOException;
use Psr\Log\LoggerInterface;

/** Creates logical alarms for direct alarm notifications, with filesystem fallback when no event exists. */
class AlarmManager
{
    public function __construct(
        private AlarmCreator $alarmCreator,
        private AlarmLimitsConfig $limitsConfig,
        private ChannelLockManager $lockManager,
        private LoggerInterface $logger
    ) {}

    public function handleAlarm(Alarm $alarm, ?EventRecordResult $eventRecord = null): void
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
            $this->fallbackThrottle($serverKey, (string) $channel);
        }
    }

    private function fallbackThrottle(string $serverKey, string $channel): void
    {
        $channel = strtolower(trim($channel));
        $spacingSeconds = AlarmLimitHelper::calculateSpacingSeconds($this->limitsConfig->resolve($serverKey, $channel));
        if ($spacingSeconds <= 0) {
            $this->logger->warning("Dropping alarm due to unavailable delivery storage and zero spacing limit [channel={$channel}, server={$serverKey}]");

            return;
        }
        if ($this->lockManager->tryAcquire($serverKey, $channel, $spacingSeconds)) {
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

    public function setLimitsConfig(AlarmLimitsConfig $limitsConfig): void
    {
        $this->limitsConfig = $limitsConfig;
    }
}

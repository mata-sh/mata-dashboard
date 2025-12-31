<?php

declare(strict_types=1);

namespace Watcher\Services\Foundation;

use PDOException;
use Psr\Log\LoggerInterface;
use Watcher\Config\Dto\AlertLimitsConfig;
use Watcher\Models\AlertQueueRepository;
use Watcher\Models\Dto\EventRecordResult;
use Watcher\Services\Foundation\Dto\Alert;
use Watcher\Services\Foundation\Dto\AlertLevel;
use Watcher\Traits\Loggable;
use Watcher\Utils\AlertLimitHelper;
use Watcher\Utils\ChannelLockManager;

/**
 * Alert manager responsible for persisting alerts into the queue.
 *
 * - Persists alerts to `dashboard_alarm_log`
 * - Resolves per-server/channel limits for fallback throttling
 * - Falls back to filesystem locks when the database is unavailable
 */
class AlertManager
{
    use Loggable;

    private AlertQueueRepository $queueRepository;
    private AlertLimitsConfig $limitsConfig;
    private ChannelLockManager $lockManager;

    public function __construct(
        AlertQueueRepository $queueRepository,
        AlertLimitsConfig $limitsConfig,
        ChannelLockManager $lockManager,
        ?LoggerInterface $logger = null
    ) {
        $this->queueRepository = $queueRepository;
        $this->limitsConfig = $limitsConfig;
        $this->lockManager = $lockManager;
        if ($logger !== null) {
            $this->setLogger($logger);
        }
    }

    /**
     * Queue alert for asynchronous dispatch.
     *
     * @param Alert                  $alert       Alert payload (type, message, etc.)
     * @param null|EventRecordResult $eventRecord Optional event metadata (contains eventId, server/app context)
     */
    public function handleAlert(Alert $alert, ?EventRecordResult $eventRecord = null): void
    {
        $isDbAvailable = $eventRecord !== null;
        $eventId = $isDbAvailable ? $eventRecord->eventId : 0;
        $serverKey = $this->normalizeServerKey($isDbAvailable ? $eventRecord->serverKey : ($alert->server ?? ''));
        $priority = $this->resolvePriority($alert->level);

        foreach ($alert->channels as $channel) {
            $this->enqueueChannel($eventId, $serverKey, (string) $channel, $priority);
        }
    }

    private function enqueueChannel(int $eventId, string $serverKey, string $channel, int $priority): void
    {
        $channel = strtolower(trim($channel));
        $effectiveLimit = $this->limitsConfig->resolve($serverKey, $channel);

        if ($eventId > 0) {
            try {
                $this->queueRepository->enqueue($eventId, $channel, $priority);

                return;
            } catch (PDOException $exception) {
                $this->log(
                    "Failed to enqueue alert; switching to filesystem throttling [channel={$channel}, server={$serverKey}, error={$exception->getMessage()}]",
                    'warning'
                );
            }
        }

        $spacingSeconds = AlertLimitHelper::calculateSpacingSeconds($effectiveLimit);
        if ($spacingSeconds <= 0) {
            $this->log(
                "Dropping alert due to unavailable queue and zero spacing limit [channel={$channel}, server={$serverKey}]",
                'warning'
            );

            return;
        }

        $acquired = $this->lockManager->tryAcquire($serverKey, $channel, $spacingSeconds);
        if ($acquired) {
            $this->log(
                "Alert queue unavailable; alert suppressed after acquiring filesystem lock [channel={$channel}, server={$serverKey}, spacing_seconds={$spacingSeconds}]",
                'warning'
            );
        }
    }

    private function resolvePriority(AlertLevel $level): int
    {
        return match ($level) {
            AlertLevel::Critical => 10,
            AlertLevel::Warning => 60,
            AlertLevel::Info => 100,
        };
    }

    private function normalizeServerKey(?string $server): string
    {
        $server = strtolower(trim($server ?? ''));

        return $server !== '' ? $server : 'global';
    }

    public function setLimitsConfig(AlertLimitsConfig $limitsConfig): void
    {
        $this->limitsConfig = $limitsConfig;
    }
}

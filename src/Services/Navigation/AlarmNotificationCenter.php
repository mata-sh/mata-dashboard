<?php

declare(strict_types=1);

namespace Mata\Services\Navigation;

use LogicException;
use Mata\Services\Cache\ApiCacheKeyFactory;
use Mata\Services\Cache\CacheStoreInterface;
use Mata\Services\Event\EventAggregatorServiceInterface;
use Mata\Services\Event\EventManager;

/**
 * Dashboard notification bell backed by alarm acknowledgements.
 *
 * Acknowledgement is global, not per-user: the bell answers "has anyone
 * dealt with this alarm yet". The acting username is recorded in
 * `alarms.acknowledged_by` as an audit trail.
 *
 * This is distinct from SystemNotificationManager which handles system-wide
 * notifications like database availability banners.
 */
class AlarmNotificationCenter
{
    /** Severity levels shown as bell pills, in display order. */
    public const PILL_LEVELS = ['warning', 'error', 'critical'];

    private const COUNTS_CACHE_TTL = 15;

    public function __construct(
        private EventManager $eventManager,
        private CacheStoreInterface $cacheStore,
        private ApiCacheKeyFactory $cacheKeyFactory,
    ) {}

    /**
     * Get unacknowledged alarm counts per severity level, zero-filled.
     *
     * Used for the topbar bell pills. Levels outside PILL_LEVELS (info)
     * are included under their own key so API consumers still see them.
     *
     * @return array<string,int> level => count
     */
    public function getUnacknowledgedCounts(): array
    {
        $cacheKey = $this->countsCacheKey();
        $cached = $this->cacheStore->get($cacheKey, self::COUNTS_CACHE_TTL);
        if ($cached !== null) {
            return $this->normalizeCounts($cached->value);
        }

        $counts = $this->normalizeCounts($this->aggregator()->getUnacknowledgedAlarmCounts());
        $this->cacheStore->put($cacheKey, $counts);

        return $counts;
    }

    /**
     * Acknowledge a single alarm.
     *
     * @param int    $alarmId Alarm identifier
     * @param string $user    Username performing the acknowledgement
     *
     * @return bool True if the alarm was newly acknowledged
     */
    public function acknowledge(int $alarmId, string $user): bool
    {
        $acknowledged = $this->aggregator()->acknowledgeAlarm($alarmId, $user);
        $this->cacheStore->delete($this->countsCacheKey());

        return $acknowledged;
    }

    /**
     * Acknowledge all unacknowledged alarms.
     *
     * @param string $user Username performing the acknowledgement
     *
     * @return int Number of alarms acknowledged
     */
    public function acknowledgeAll(string $user): int
    {
        $acknowledged = $this->aggregator()->acknowledgeAllAlarms($user);
        $this->cacheStore->delete($this->countsCacheKey());

        return $acknowledged;
    }

    /**
     * @param array<int|string,mixed> $raw
     *
     * @return array<string,int>
     */
    private function normalizeCounts(array $raw): array
    {
        $counts = ['info' => 0, 'warning' => 0, 'error' => 0, 'critical' => 0];
        foreach ($raw as $level => $count) {
            if (is_string($level) && is_int($count)) {
                $counts[$level] = ($counts[$level] ?? 0) + $count;
            }
        }

        return $counts;
    }

    private function countsCacheKey(): string
    {
        return $this->cacheKeyFactory->createDashboardKey('notifications/unacknowledged-counts');
    }

    private function aggregator(): EventAggregatorServiceInterface
    {
        if ($this->eventManager->aggregator === null) {
            throw new LogicException(
                'EventAggregator not initialized. Call setEventAggregator() on EventManager before using AlarmNotificationCenter.'
            );
        }

        return $this->eventManager->aggregator;
    }
}

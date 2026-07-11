<?php

declare(strict_types=1);

namespace Mata\Services\Navigation;

use LogicException;
use Mata\Services\Event\EventAggregatorServiceInterface;
use Mata\Services\Event\EventManager;

/**
 * Dashboard notification bell backed by alarm acknowledgements.
 *
 * Acknowledgement is global, not per-user: the bell answers "has anyone
 * dealt with this alarm yet". The acting username is recorded in
 * `alarms.acknowledged_by` as an audit trail.
 *
 * This is distinct from NotificationManager which handles system-wide
 * notifications like database availability banners.
 */
class NotificationCenter
{
    /** Severity levels shown as bell pills, in display order. */
    public const PILL_LEVELS = ['warning', 'error', 'critical'];

    private EventManager $eventManager;

    public function __construct(EventManager $eventManager)
    {
        $this->eventManager = $eventManager;
    }

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
        $raw = $this->aggregator()->getUnacknowledgedAlarmCounts();

        $counts = ['info' => 0, 'warning' => 0, 'error' => 0, 'critical' => 0];
        foreach ($raw as $level => $count) {
            $counts[$level] = ($counts[$level] ?? 0) + $count;
        }

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
        return $this->aggregator()->acknowledgeAlarm($alarmId, $user);
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
        return $this->aggregator()->acknowledgeAllAlarms($user);
    }

    private function aggregator(): EventAggregatorServiceInterface
    {
        if ($this->eventManager->aggregator === null) {
            throw new LogicException(
                'EventAggregator not initialized. Call setEventAggregator() on EventManager before using NotificationCenter.'
            );
        }

        return $this->eventManager->aggregator;
    }
}

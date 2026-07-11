<?php

declare(strict_types=1);

namespace Mata\Services\Event;

/**
 * Event aggregator interface.
 *
 * Defines the contract for event querying/aggregation services,
 * allowing real and mock implementations.
 */
interface EventAggregatorServiceInterface
{
    /**
     * Get unevaluated events for alarm decision processing.
     *
     * @param int                       $limit       Maximum number of candidates to fetch
     * @param null|array<string>|string $levelFilter Filter by severity level(s) (critical, warning, info)
     *
     * @return array<int,array<string,mixed>>
     */
    public function getAlertDecisionCandidates(
        int $limit = 100,
        array|string|null $levelFilter = null
    ): array;

    public function markEventEvaluated(int $eventId): void;

    /**
     * Query events by context with flexible filtering.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getLastEventsByContext(
        ?string $type = null,
        ?string $context = null,
        int|string|null $server = null,
        int|string|null $app = null,
        int $maxAgeMinutes = 0
    ): array;

    /**
     * Query alarm events with flexible filtering.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getLastAlarmsByContext(
        ?string $type = null,
        ?string $message = null,
        int|string|null $server = null,
        int|string|null $app = null,
        int $maxAgeMinutes = 0
    ): array;

    /** @return array<int,array<string,mixed>> */
    public function getLatestEvents(int $limit = 50): array;

    /** @return array<int,array<string,mixed>> */
    public function getLatestAlarms(int $limit = 50, bool $unacknowledgedOnly = false): array;

    /**
     * Count unacknowledged alarms grouped by severity level.
     *
     * @return array<string,int> level => count
     */
    public function getUnacknowledgedAlarmCounts(): array;

    /**
     * Acknowledge a single alarm for a user.
     *
     * @return bool True if the alarm was newly acknowledged
     */
    public function acknowledgeAlarm(int $alarmId, string $user): bool;

    /**
     * Acknowledge all unacknowledged alarms for a user.
     *
     * @return int Number of alarms acknowledged
     */
    public function acknowledgeAllAlarms(string $user): int;

    /**
     * Remove alarm records older than specified days.
     */
    public function clearOldAlarms(int $days = 365): void;

    /**
     * Remove event records older than specified days.
     */
    public function clearOldEvents(int $days = 180): void;
}

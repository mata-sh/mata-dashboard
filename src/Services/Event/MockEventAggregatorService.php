<?php

declare(strict_types=1);

namespace Mata\Services\Event;

use DateInterval;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Mock event aggregator for demo/debug scenarios.
 *
 * Returns preloaded events/alarms instead of querying the database.
 */
final class MockEventAggregatorService implements EventAggregatorServiceInterface
{
    private const DEFAULT_ALARM_DECISION_LIMIT = 100;
    private const DEFAULT_LATEST_EVENTS_LIMIT = 50;
    private const DEFAULT_LATEST_ALARMS_LIMIT = 50;

    /** @var array<int,array<string,mixed>> */
    private array $events = [];

    /** @var array<int,array<string,mixed>> */
    private array $alarms = [];

    /** @var array<int,bool> */
    private array $evaluatedEventIds = [];

    private LoggerInterface $logger;

    /**
     * @param null|array<string,mixed> $demoData
     */
    public function __construct(
        ?LoggerInterface $logger = null,
        ?array $demoData = null
    ) {
        $this->logger = $logger ?? new NullLogger();

        $data = $demoData ?? self::defaultDemoData();
        $this->events = $this->normalizeEvents($data['events'] ?? []);
        $this->alarms = $this->normalizeAlarms($data['alarms'] ?? []);
    }

    /**
     * Add enough unacknowledged alarms to demonstrate every bell severity.
     *
     * Existing demo alarms are preserved. Missing severity levels receive one
     * recent sample alarm so the bell is never empty or limited to one pill.
     *
     * @param array<string,mixed> $demoData
     *
     * @return array<string,mixed>
     */
    public static function withNotificationShowcase(array $demoData): array
    {
        $events = is_array($demoData['events'] ?? null) ? $demoData['events'] : [];
        $alarms = is_array($demoData['alarms'] ?? null) ? $demoData['alarms'] : [];
        $levels = [];
        foreach ($alarms as $alarm) {
            if (is_array($alarm) && is_string($alarm['level'] ?? null)) {
                $levels[strtolower($alarm['level'])] = true;
            }
        }

        $samples = [
            'warning' => ['id' => 990001, 'event_id' => 980001, 'type' => 'library_outdated', 'message' => 'A dependency update is available'],
            'error' => ['id' => 990002, 'event_id' => 980002, 'type' => 'upstream_latency', 'message' => 'Checkout API latency exceeded 1.5s'],
            'critical' => ['id' => 990003, 'event_id' => 980003, 'type' => 'disk_usage', 'message' => 'Disk usage exceeded 90% on /var'],
        ];
        $timestamp = gmdate(DATE_ATOM);

        foreach ($samples as $level => $sample) {
            if (isset($levels[$level])) {
                continue;
            }

            $events[] = [
                'id' => $sample['event_id'],
                'datetime' => $timestamp,
                'type' => $sample['type'],
                'message' => $sample['message'],
                'level' => $level,
                'server' => 'demo-server',
                'app' => 'demo-app',
            ];
            $alarms[] = [
                'id' => $sample['id'],
                'event_id' => $sample['event_id'],
                'created_at' => $timestamp,
                'datetime' => $timestamp,
                'type' => $sample['type'],
                'message' => $sample['message'],
                'level' => $level,
                'server' => 'demo-server',
                'app' => 'demo-app',
                'deliveries' => [],
            ];
        }

        $demoData['events'] = $events;
        $demoData['alarms'] = $alarms;

        return $demoData;
    }

    /**
     * Load demo event data from a JSON file.
     *
     * @return array<string,mixed>
     */
    public static function loadDemoData(?string $path, ?LoggerInterface $logger = null): array
    {
        $logger = $logger ?? new NullLogger();

        if ($path === null || $path === '') {
            return self::defaultDemoData();
        }

        if (!is_file($path)) {
            $logger->warning('Mock event data file not found, using defaults.', ['path' => $path]);

            return self::defaultDemoData();
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            $logger->warning('Mock event data file unreadable, using defaults.', ['path' => $path]);

            return self::defaultDemoData();
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            $logger->warning('Mock event data JSON invalid, using defaults.', ['path' => $path]);

            return self::defaultDemoData();
        }

        return $decoded;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getAlarmDecisionCandidates(
        int $limit = self::DEFAULT_ALARM_DECISION_LIMIT,
        array|string|null $levelFilter = null
    ): array {
        $limit = max(1, (int) $limit);
        $levels = $this->normalizeLevelFilter($levelFilter);

        $events = $this->sortByTime($this->events, 'datetime', true);
        $candidates = [];

        foreach ($events as $event) {
            $eventId = (int) ($event['id'] ?? 0);
            if ($eventId > 0 && isset($this->evaluatedEventIds[$eventId])) {
                continue;
            }

            if ($levels !== null) {
                $level = strtolower((string) ($event['level'] ?? ''));
                if (!in_array($level, $levels, true)) {
                    continue;
                }
            }

            $candidates[] = $this->normalizeDecisionCandidate($event);
            if (count($candidates) >= $limit) {
                break;
            }
        }

        $this->logger->debug('Mock alarm decision candidates fetched.', [
            'count' => count($candidates),
            'types' => $this->summarizeTypes($candidates, 'type'),
            'limit' => $limit,
            'levels' => $levels,
        ]);

        return $candidates;
    }

    public function markEventEvaluated(int $eventId, ?array $alarmDecision = null): void
    {
        if ($eventId > 0) {
            $this->evaluatedEventIds[$eventId] = true;
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getLastEventsByContext(
        ?string $type = null,
        ?string $context = null,
        int|string|null $server = null,
        int|string|null $app = null,
        int $maxAgeMinutes = 0
    ): array {
        $serverId = $server === '' ? null : $server;
        $appId = $app === '' ? null : $app;

        $events = $this->filterEvents(
            $this->events,
            $type,
            $context,
            $serverId,
            $appId,
            $maxAgeMinutes
        );

        $events = $this->sortByTime($events, 'datetime', false);

        $this->logger->debug('Mock events fetched by context.', [
            'count' => count($events),
            'types' => $this->summarizeTypes($events, 'type'),
            'filter' => [
                'type' => $type,
                'context' => $context,
                'server' => $serverId,
                'app' => $appId,
                'max_age_minutes' => $maxAgeMinutes,
            ],
        ]);

        return $events;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getLastAlarmsByContext(
        ?string $type = null,
        ?string $message = null,
        int|string|null $server = null,
        int|string|null $app = null,
        int $maxAgeMinutes = 0
    ): array {
        $serverId = $server === '' ? null : $server;
        $appId = $app === '' ? null : $app;

        $alarms = $this->filterAlarms(
            $this->alarms,
            $type,
            $message,
            $serverId,
            $appId,
            $maxAgeMinutes
        );

        $alarms = $this->sortByTime($alarms, 'created_at', false);

        $this->logger->debug('Mock alarms fetched by context.', [
            'count' => count($alarms),
            'types' => $this->summarizeTypes($alarms, 'type'),
            'filter' => [
                'type' => $type,
                'message' => $message,
                'server' => $serverId,
                'app' => $appId,
                'max_age_minutes' => $maxAgeMinutes,
            ],
        ]);

        return $alarms;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getLatestEvents(int $limit = self::DEFAULT_LATEST_EVENTS_LIMIT): array
    {
        $limit = max(1, (int) $limit);
        $events = $this->sortByTime($this->events, 'datetime', false);

        $events = array_slice($events, 0, $limit);

        $this->logger->debug('Mock latest events fetched.', [
            'count' => count($events),
            'types' => $this->summarizeTypes($events, 'type'),
            'limit' => $limit,
        ]);

        return $events;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getLatestAlarms(int $limit = self::DEFAULT_LATEST_ALARMS_LIMIT, bool $unacknowledgedOnly = false): array
    {
        $limit = max(1, (int) $limit);
        $alarms = $this->sortByTime($this->alarms, 'created_at', false);

        if ($unacknowledgedOnly) {
            $alarms = array_values(array_filter(
                $alarms,
                static fn (array $alarm): bool => empty($alarm['acknowledged_at'])
            ));
        }

        $alarms = array_slice($alarms, 0, $limit);

        $this->logger->debug('Mock latest alarms fetched.', [
            'count' => count($alarms),
            'types' => $this->summarizeTypes($alarms, 'type'),
            'limit' => $limit,
        ]);

        return $alarms;
    }

    /**
     * @return array<string,int> level => count
     */
    public function getUnacknowledgedAlarmCounts(): array
    {
        $counts = [];
        foreach ($this->alarms as $alarm) {
            if (!empty($alarm['acknowledged_at'])) {
                continue;
            }
            $level = (string) ($alarm['level'] ?? 'info');
            $counts[$level] = ($counts[$level] ?? 0) + 1;
        }

        return $counts;
    }

    public function acknowledgeAlarm(int $alarmId, string $user): bool
    {
        foreach ($this->alarms as &$alarm) {
            if ((int) ($alarm['id'] ?? 0) === $alarmId && empty($alarm['acknowledged_at'])) {
                $alarm['acknowledged_at'] = date('Y-m-d H:i:s');
                $alarm['acknowledged_by'] = $user;

                return true;
            }
        }

        return false;
    }

    public function acknowledgeAllAlarms(string $user): int
    {
        $count = 0;
        foreach ($this->alarms as &$alarm) {
            if (empty($alarm['acknowledged_at'])) {
                $alarm['acknowledged_at'] = date('Y-m-d H:i:s');
                $alarm['acknowledged_by'] = $user;
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @return array<string,mixed>
     */
    private static function defaultDemoData(): array
    {
        return [
            'events' => [],
            'alarms' => [],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $events
     *
     * @return array<int,array<string,mixed>>
     */
    private function normalizeEvents(array $events): array
    {
        $normalized = [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $normalized[] = $this->normalizeEvent($event);
        }

        return $normalized;
    }

    /**
     * @param array<int,array<string,mixed>> $alarms
     *
     * @return array<int,array<string,mixed>>
     */
    private function normalizeAlarms(array $alarms): array
    {
        $normalized = [];
        foreach ($alarms as $alarm) {
            if (!is_array($alarm)) {
                continue;
            }
            $normalized[] = $this->normalizeAlarm($alarm);
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $event
     *
     * @return array<string,mixed>
     */
    private function normalizeEvent(array $event): array
    {
        if (!isset($event['server']) && isset($event['server_name'])) {
            $event['server'] = $event['server_name'];
        }
        if (!isset($event['server_name']) && isset($event['server'])) {
            $event['server_name'] = $event['server'];
        }
        if (!isset($event['app']) && isset($event['app_code'])) {
            $event['app'] = $event['app_code'];
        }
        if (!isset($event['app_code']) && isset($event['app'])) {
            $event['app_code'] = $event['app'];
        }
        if (!isset($event['app_name']) && isset($event['app'])) {
            $event['app_name'] = $event['app'];
        }
        unset($event['metrics']);

        return $event;
    }

    /**
     * @param array<string,mixed> $alarm
     *
     * @return array<string,mixed>
     */
    private function normalizeAlarm(array $alarm): array
    {
        if (!isset($alarm['server']) && isset($alarm['server_name'])) {
            $alarm['server'] = $alarm['server_name'];
        }
        if (!isset($alarm['server_name']) && isset($alarm['server'])) {
            $alarm['server_name'] = $alarm['server'];
        }
        if (!isset($alarm['app']) && isset($alarm['app_code'])) {
            $alarm['app'] = $alarm['app_code'];
        }
        if (!isset($alarm['app_code']) && isset($alarm['app'])) {
            $alarm['app_code'] = $alarm['app'];
        }

        $rawDeliveries = $alarm['deliveries'] ?? [];
        if (!is_array($rawDeliveries)) {
            $rawDeliveries = [];
        }

        $deliveries = [];
        foreach ($rawDeliveries as $channel => $delivery) {
            $channel = strtolower(trim((string) $channel));
            if ($channel === '' || !is_array($delivery)) {
                continue;
            }

            $deliveries[$channel] = [
                'status' => (string) ($delivery['status'] ?? 'pending'),
                'attempts' => (int) ($delivery['attempts'] ?? 0),
                'processed_at' => isset($delivery['processed_at']) ? (string) $delivery['processed_at'] : null,
                'next_retry_at' => isset($delivery['next_retry_at']) ? (string) $delivery['next_retry_at'] : null,
            ];
        }
        $alarm['deliveries'] = $deliveries === [] ? (object) [] : $deliveries;
        unset($alarm['channel'], $alarm['status']);

        return $alarm;
    }

    /**
     * @param array<string,mixed> $event
     *
     * @return array<string,mixed>
     */
    private function normalizeDecisionCandidate(array $event): array
    {
        unset($event['metrics']);

        return $event;
    }

    /**
     * @param null|array<string>|string $levelFilter
     *
     * @return null|array<int,string>
     */
    private function normalizeLevelFilter(array|string|null $levelFilter): ?array
    {
        if ($levelFilter === null) {
            return null;
        }

        $levels = is_array($levelFilter) ? $levelFilter : [$levelFilter];
        $normalized = [];
        foreach ($levels as $level) {
            if (!is_string($level)) {
                continue;
            }
            $level = strtolower(trim($level));
            if ($level === '') {
                continue;
            }
            $normalized[] = $level;
        }

        return $normalized === [] ? null : array_values(array_unique($normalized));
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<string,int>
     */
    private function summarizeTypes(array $rows, string $field): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $value = $row[$field] ?? 'unknown';
            $key = is_string($value) && $value !== '' ? $value : 'unknown';
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @param array<int,array<string,mixed>> $events
     *
     * @return array<int,array<string,mixed>>
     */
    private function filterEvents(
        array $events,
        ?string $type,
        ?string $context,
        int|string|null $serverId,
        int|string|null $applicationId,
        int $maxAgeMinutes
    ): array {
        $filtered = [];
        foreach ($events as $event) {
            if ($type !== null && $type !== '' && ($event['type'] ?? null) !== $type) {
                continue;
            }
            if ($context !== null && ($event['context'] ?? null) !== $context) {
                continue;
            }
            if ($serverId !== null && (int) ($event['server_id'] ?? 0) !== (int) $serverId) {
                continue;
            }
            if ($applicationId !== null && (int) ($event['application_id'] ?? 0) !== (int) $applicationId) {
                continue;
            }
            if (!$this->withinMaxAge($event['datetime'] ?? null, $maxAgeMinutes)) {
                continue;
            }
            $filtered[] = $event;
        }

        return $filtered;
    }

    /**
     * @param array<int,array<string,mixed>> $alarms
     *
     * @return array<int,array<string,mixed>>
     */
    private function filterAlarms(
        array $alarms,
        ?string $type,
        ?string $message,
        int|string|null $serverId,
        int|string|null $applicationId,
        int $maxAgeMinutes
    ): array {
        $filtered = [];
        foreach ($alarms as $alarm) {
            if ($type !== null && $type !== '' && ($alarm['type'] ?? null) !== $type) {
                continue;
            }
            if ($message !== null && $message !== '' && ($alarm['message'] ?? null) !== $message) {
                continue;
            }
            if ($serverId !== null && (int) ($alarm['server_id'] ?? 0) !== (int) $serverId) {
                continue;
            }
            if ($applicationId !== null && (int) ($alarm['application_id'] ?? 0) !== (int) $applicationId) {
                continue;
            }
            if (!$this->withinMaxAge($alarm['created_at'] ?? null, $maxAgeMinutes)) {
                continue;
            }
            $filtered[] = $alarm;
        }

        return $filtered;
    }

    private function withinMaxAge(mixed $value, int $maxAgeMinutes): bool
    {
        if ($maxAgeMinutes <= 0) {
            return true;
        }

        $eventTime = $this->parseDateTime($value);
        if ($eventTime === null) {
            return false;
        }

        $threshold = (new DateTimeImmutable())->sub(new DateInterval('PT' . $maxAgeMinutes . 'M'));

        return $eventTime >= $threshold;
    }

    private function parseDateTime(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<int,array<string,mixed>>
     */
    private function sortByTime(array $rows, string $field, bool $ascending): array
    {
        usort($rows, function (array $left, array $right) use ($field, $ascending): int {
            $leftTime = $this->parseDateTime($left[$field] ?? null);
            $rightTime = $this->parseDateTime($right[$field] ?? null);

            if ($leftTime === null && $rightTime === null) {
                return 0;
            }
            if ($leftTime === null) {
                return 1;
            }
            if ($rightTime === null) {
                return -1;
            }

            if ($leftTime == $rightTime) {
                return 0;
            }

            if ($ascending) {
                return $leftTime < $rightTime ? -1 : 1;
            }

            return $leftTime > $rightTime ? -1 : 1;
        });

        return $rows;
    }
}

<?php

declare(strict_types=1);

namespace Mata\Services\Retention;

use DateInterval;
use DateTimeImmutable;
use Mata\Database\DatabaseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/** Performs bounded retention cleanup for alarms, events, and metric tables. */
final class RetentionCleanupService
{
    private const DEFAULT_BATCH_LIMIT = 5000;
    private const MAX_BATCH_LIMIT = 100000;

    /** @var array<string,array{tier:string,column:string}> */
    private const METRIC_TABLES = [
        'server_metrics_raw' => ['tier' => 'raw', 'column' => 'collected_at'],
        'app_metrics_raw' => ['tier' => 'raw', 'column' => 'collected_at'],
        'server_metrics_hourly' => ['tier' => 'hourly', 'column' => 'hour_timestamp'],
        'app_metrics_hourly' => ['tier' => 'hourly', 'column' => 'hour_timestamp'],
        'server_metrics_daily' => ['tier' => 'daily', 'column' => 'date'],
        'app_metrics_daily' => ['tier' => 'daily', 'column' => 'date'],
    ];

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @param array<string,mixed> $retentionConfig
     * @param null|callable       $tick            Invoked after each delete statement
     */
    public function cleanup(
        DateTimeImmutable $now,
        array $retentionConfig,
        ?callable $tick = null
    ): RetentionCleanupResult {
        $days = [
            'alarms' => $this->retentionDays($retentionConfig, 'alarms', 'retention_days'),
            'events' => $this->retentionDays($retentionConfig, 'events', 'retention_days'),
            'raw' => $this->retentionDays($retentionConfig, 'metrics', 'raw_retention_days'),
            'hourly' => $this->retentionDays($retentionConfig, 'metrics', 'hourly_retention_days'),
            'daily' => $this->retentionDays($retentionConfig, 'metrics', 'daily_retention_days'),
        ];
        $cutoffs = $this->cutoffs($now, $days);
        $batchLimit = $this->batchLimit($retentionConfig);
        $alarmsDeleted = 0;
        $eventsDeleted = 0;
        $metricsDeleted = array_fill_keys(array_keys(self::METRIC_TABLES), 0);
        $failures = [];
        $alarmCleanupFailed = false;

        if ($days['alarms'] > 0) {
            try {
                $this->deleteBatches(
                    fn (): int => $this->deleteAlarms($cutoffs['alarms'], $batchLimit),
                    $batchLimit,
                    $tick,
                    $alarmsDeleted
                );
            } catch (Throwable $exception) {
                $alarmCleanupFailed = true;
                $failures[] = $this->failure('alarms', $exception);
            }
        }

        if (!$alarmCleanupFailed && $days['events'] > 0) {
            try {
                $this->deleteBatches(
                    fn (): int => $this->deleteEvents($cutoffs['events'], $batchLimit),
                    $batchLimit,
                    $tick,
                    $eventsDeleted
                );
            } catch (Throwable $exception) {
                $failures[] = $this->failure('events', $exception);
            }
        }

        foreach (self::METRIC_TABLES as $table => $definition) {
            if ($days[$definition['tier']] <= 0) {
                continue;
            }

            try {
                $this->deleteBatches(
                    fn (): int => $this->deleteMetrics(
                        $table,
                        $definition['column'],
                        $cutoffs[$definition['tier']],
                        $batchLimit
                    ),
                    $batchLimit,
                    $tick,
                    $metricsDeleted[$table]
                );
            } catch (Throwable $exception) {
                $failures[] = $this->failure($table, $exception);
            }
        }

        return new RetentionCleanupResult(
            $alarmsDeleted,
            $eventsDeleted,
            $metricsDeleted,
            $failures,
            $cutoffs
        );
    }

    /** @param array<string,int> $days
     *
     * @return array<string,string>
     */
    private function cutoffs(DateTimeImmutable $now, array $days): array
    {
        $cutoffs = [];
        foreach ($days as $category => $retentionDays) {
            $cutoff = $now->sub(new DateInterval('P' . $retentionDays . 'D'));
            $cutoffs[$category] = $category === 'daily'
                ? $cutoff->format('Y-m-d')
                : $cutoff->format('Y-m-d H:i:s');
        }

        return $cutoffs;
    }

    private function deleteAlarms(string $cutoff, int $batchLimit): int
    {
        $sql = <<<SQL
            DELETE FROM alarms
            WHERE id IN (
                SELECT id FROM (
                    SELECT a.id
                    FROM alarms a
                    WHERE a.created_at < :cutoff
                      AND NOT EXISTS (
                          SELECT 1
                          FROM alarm_deliveries d
                          WHERE d.alarm_id = a.id
                            AND d.status IN ('pending', 'processing', 'retry')
                      )
                    ORDER BY a.created_at ASC, a.id ASC
                    LIMIT {$batchLimit}
                ) AS batch
            )
            SQL;

        return $this->deleted($this->db->runQuery($sql, ['cutoff' => $cutoff]));
    }

    private function deleteEvents(string $cutoff, int $batchLimit): int
    {
        $sql = <<<SQL
            DELETE FROM events
            WHERE id IN (
                SELECT id FROM (
                    SELECT e.id
                    FROM events e
                    WHERE e.datetime < :cutoff
                      AND NOT EXISTS (
                          SELECT 1
                          FROM alarms a
                          WHERE a.event_id = e.id
                      )
                    ORDER BY e.datetime ASC, e.id ASC
                    LIMIT {$batchLimit}
                ) AS batch
            )
            SQL;

        return $this->deleted($this->db->runQuery($sql, ['cutoff' => $cutoff]));
    }

    private function deleteMetrics(string $table, string $column, string $cutoff, int $batchLimit): int
    {
        $sql = <<<SQL
            DELETE FROM {$table}
            WHERE id IN (
                SELECT id FROM (
                    SELECT m.id
                    FROM {$table} m
                    WHERE m.{$column} < :cutoff
                    ORDER BY m.{$column} ASC, m.id ASC
                    LIMIT {$batchLimit}
                ) AS batch
            )
            SQL;

        return $this->deleted($this->db->runQuery($sql, ['cutoff' => $cutoff]));
    }

    /** @param callable():int $delete */
    private function deleteBatches(callable $delete, int $batchLimit, ?callable $tick, int &$total): void
    {
        do {
            try {
                $deleted = $delete();
                $total += $deleted;
            } finally {
                $this->tick($tick);
            }
        } while ($deleted === $batchLimit);
    }

    /** @param array<string,mixed> $config */
    private function retentionDays(array $config, string $section, string $key): int
    {
        $values = $config[$section] ?? [];
        $value = is_array($values) ? ($values[$key] ?? 0) : 0;

        return max(0, (int) $value);
    }

    /** @param array<string,mixed> $config */
    private function batchLimit(array $config): int
    {
        $pruning = $config['pruning'] ?? [];
        $value = is_array($pruning) ? ($pruning['batch_limit'] ?? self::DEFAULT_BATCH_LIMIT) : self::DEFAULT_BATCH_LIMIT;
        if (!is_numeric($value) || (int) $value < 1) {
            return self::DEFAULT_BATCH_LIMIT;
        }

        return min(self::MAX_BATCH_LIMIT, (int) $value);
    }

    /** @return array{category:string,message:string} */
    private function failure(string $category, Throwable $exception): array
    {
        $this->logger->error('retention_cleanup.failed', [
            'category' => $category,
            'error' => $exception->getMessage(),
        ]);

        return [
            'category' => $category,
            'message' => $exception->getMessage(),
        ];
    }

    /** @param array<mixed>|int $result */
    private function deleted(array|int $result): int
    {
        return is_int($result) ? $result : 0;
    }

    private function tick(?callable $tick): void
    {
        if ($tick !== null) {
            $tick();
        }
    }
}

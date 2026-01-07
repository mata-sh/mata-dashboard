#!/usr/bin/php8.2
<?php

declare(strict_types=1);

use Watcher\Models\ServerMetrics\ServerMetricsAggregator;
use Watcher\Models\ServerMetrics\ServerMetricsRepository;

$requiresDatabase = true;

extract(require_once __DIR__ . '/bootstrap.php');
extract(require_once __DIR__ . '/bootstrap_events.php');

if (!$cron->acquireLock()) {
    exit("Already running.\n");
}

$cron->startTimer();
$cron->log('Started');

try {
    // All event/alert infrastructure now loaded via bootstrap_events.php
    $dataRetentionConfig = $monitoringSettings['data_retention'] ?? [];
    $hourlyLagMinutes = (int) max(0, $dataRetentionConfig['aggregation']['hourly']['lag_minutes'] ?? 5);
    $catchUpHours = (int) max(1, $dataRetentionConfig['aggregation']['hourly']['catch_up_hours'] ?? 24);
    $dailyLagMinutes = (int) max(0, $dataRetentionConfig['aggregation']['daily']['lag_minutes'] ?? 30);
    $rawRetentionDays = (int) max(0, $dataRetentionConfig['raw_retention_days'] ?? 30);
    $pruneBatchLimit = (int) max(0, $dataRetentionConfig['pruning']['batch_limit'] ?? 0);

    $repository = new ServerMetricsRepository($db, $idResolver, $configSyncRepository);
    $aggregator = new ServerMetricsAggregator($db, $repository, $log);

    $now = new DateTimeImmutable();
    $hourlyAsOf = $hourlyLagMinutes > 0
        ? $now->sub(new DateInterval('PT' . $hourlyLagMinutes . 'M'))
        : $now;
    $targetHour = $hourlyAsOf
        ->setTime((int) $hourlyAsOf->format('H'), 0, 0)
        ->sub(new DateInterval('PT1H'))
    ;

    $windowStart = $catchUpHours > 1
        ? $targetHour->sub(new DateInterval('PT' . ($catchUpHours - 1) . 'H'))
        : $targetHour;

    $dailyAsOfDay = (
        $dailyLagMinutes > 0
        ? $now->sub(new DateInterval('PT' . $dailyLagMinutes . 'M'))
        : $now
    )->setTime(0, 0, 0);

    $stats = [
        'servers_considered' => 0,
        'servers_skipped' => 0,
        'hourly_aggregated' => 0,
        'hourly_skipped' => 0,
        'hourly_errors' => 0,
        'daily_aggregated' => 0,
        'daily_errors' => 0,
        'pruned' => 0,
    ];

    foreach ($monitoringSettings['servers'] ?? [] as $serverName => $serverConfig) {
        if (!is_array($serverConfig)) {
            continue;
        }

        if (str_starts_with((string) $serverName, '_')) {
            ++$stats['servers_skipped'];

            continue;
        }

        if (!isServerActive($serverConfig)) {
            $log->info('aggregate_server_metrics.skip_inactive', ['server' => $serverName]);
            ++$stats['servers_skipped'];

            continue;
        }

        ++$stats['servers_considered'];
        $cron->touchHeartbeat();

        try {
            $latestAggregates = $repository->getLatestAggregates((string) $serverName);
        } catch (Throwable $exception) {
            $log->error('aggregate_server_metrics.latest_lookup_failed', [
                'server' => $serverName,
                'error' => $exception->getMessage(),
            ]);
            ++$stats['hourly_errors'];

            continue;
        }

        $latestHourly = $latestAggregates['hourly']['hour_timestamp'] ?? null;
        $hourCursor = $latestHourly instanceof DateTimeImmutable
            ? $latestHourly->add(new DateInterval('PT1H'))
            : $windowStart;

        if ($hourCursor < $windowStart) {
            $hourCursor = $windowStart;
        }

        if ($hourCursor > $targetHour) {
            ++$stats['hourly_skipped'];

            continue;
        }

        $dailyCandidates = [];
        $hourInterval = new DateInterval('PT1H');

        while ($hourCursor <= $targetHour) {
            $cron->touchHeartbeat();

            try {
                $aggregated = $aggregator->aggregateHour((string) $serverName, $hourCursor);
            } catch (Throwable $exception) {
                $log->error('aggregate_server_metrics.hour_failed', [
                    'server' => $serverName,
                    'hour' => $hourCursor->format(DateTimeInterface::ATOM),
                    'error' => $exception->getMessage(),
                ]);
                ++$stats['hourly_errors'];
                $hourCursor = $hourCursor->add($hourInterval);

                continue;
            }

            if ($aggregated) {
                ++$stats['hourly_aggregated'];

                if ($hourCursor->format('H') === '23') {
                    $dayDate = $hourCursor->setTime(0, 0, 0);
                    $dailyCandidates[$dayDate->format('Y-m-d')] = $dayDate;
                }
            } else {
                ++$stats['hourly_skipped'];
            }

            $hourCursor = $hourCursor->add($hourInterval);
        }

        $latestDaily = $latestAggregates['daily']['date'] ?? null;
        $lastDailyDate = $latestDaily instanceof DateTimeImmutable
            ? $latestDaily->setTime(0, 0, 0)
            : null;

        foreach ($dailyCandidates as $dayKey => $dayDate) {
            if ($dayDate >= $dailyAsOfDay) {
                continue;
            }

            if ($lastDailyDate !== null && $dayDate <= $lastDailyDate) {
                continue;
            }

            $cron->touchHeartbeat();

            try {
                if ($aggregator->aggregateDay((string) $serverName, $dayDate)) {
                    ++$stats['daily_aggregated'];
                    $lastDailyDate = $dayDate;
                } else {
                    $log->info('aggregate_server_metrics.daily_no_data', [
                        'server' => $serverName,
                        'date' => $dayKey,
                    ]);
                }
            } catch (Throwable $exception) {
                $log->error('aggregate_server_metrics.daily_failed', [
                    'server' => $serverName,
                    'date' => $dayKey,
                    'error' => $exception->getMessage(),
                ]);
                ++$stats['daily_errors'];
            }
        }
    }

    if ($rawRetentionDays > 0 && $stats['hourly_aggregated'] > 0) {
        $pruneThreshold = $now->sub(new DateInterval('P' . $rawRetentionDays . 'D'));
        $deleted = $aggregator->pruneRawData($pruneThreshold, $pruneBatchLimit);
        $stats['pruned'] = $deleted;

        if ($deleted > 0) {
            $log->info('aggregate_server_metrics.pruned_raw', [
                'before' => $pruneThreshold->format(DateTimeInterface::ATOM),
                'deleted' => $deleted,
            ]);
        }
    }

    $duration = $cron->endTimer();
    $summary = sprintf(
        'Finished in %.3fs (memory: %s) | servers=%d aggregated_hourly=%d aggregated_daily=%d pruned=%d errors=%d',
        $duration,
        $cron->memoryUsage(),
        $stats['servers_considered'],
        $stats['hourly_aggregated'],
        $stats['daily_aggregated'],
        $stats['pruned'],
        $stats['hourly_errors'] + $stats['daily_errors']
    );

    $cron->log($summary);
    $log->info('aggregate_server_metrics.summary', [
        'duration_seconds' => $duration,
        'memory' => $cron->memoryUsage(),
        'stats' => $stats,
        'hour_target' => $targetHour->format(DateTimeInterface::ATOM),
        'window_start' => $windowStart->format(DateTimeInterface::ATOM),
    ]);
    $cron->touchHeartbeat();

} catch (Throwable $exception) {
    $cron->log('ERROR: ' . $exception->getMessage());
    $log->critical('aggregate_server_metrics.unhandled_exception', [
        'error' => $exception->getMessage(),
    ]);
} finally {
    $cron->releaseLock();
}

/**
 * @param array<string,mixed> $config
 */
function isServerActive(array $config): bool
{
    if ((normalizeFlag($config['disabled'] ?? null) ?? false) === true) {
        return false;
    }

    foreach (['enabled', 'active', 'monitoring_enabled'] as $flag) {
        $normalized = normalizeFlag($config[$flag] ?? null);
        if ($normalized === false) {
            return false;
        }
    }

    return true;
}

function normalizeFlag(mixed $value): ?bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_numeric($value)) {
        return (int) $value !== 0;
    }

    if (is_string($value)) {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'off', 'disabled'], true)) {
            return false;
        }
    }

    return null;
}

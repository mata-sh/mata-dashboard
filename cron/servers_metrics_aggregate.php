#!/usr/bin/php8.2
<?php

declare(strict_types=1);

use Mata\Models\ServerMetrics\AggregationWindowPlanner;
use Mata\Models\ServerMetrics\ServerMetricsAggregator;
use Mata\Models\ServerMetrics\ServerMetricsRepository;

$requiresDatabase = true;

extract(require_once __DIR__ . '/bootstrap.php');
extract(require_once __DIR__ . '/bootstrap_events.php');

if (!$cron->acquireLock()) {
    exit("Already running.\n");
}

$cron->startTimer();
$cron->log('Started');
$exitCode = 0;

try {
    $aggregationConfig = $monitoringSettings['data_retention']['aggregation'] ?? [];
    $hourlyLagMinutes = max(0, (int) ($aggregationConfig['hourly']['lag_minutes'] ?? 5));
    $hourlyCatchUpHours = max(1, (int) ($aggregationConfig['hourly']['catch_up_hours'] ?? 24));
    $dailyLagMinutes = max(0, (int) ($aggregationConfig['daily']['lag_minutes'] ?? 30));
    $dailyCatchUpDays = max(1, (int) ($aggregationConfig['daily']['catch_up_days'] ?? 7));

    $repository = new ServerMetricsRepository($db, $idResolver, $configSyncRepository);
    $aggregator = new ServerMetricsAggregator($db, $repository, $log);
    $planner = new AggregationWindowPlanner();
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $hourlyWindows = $planner->hourlyWindows($now, $hourlyLagMinutes, $hourlyCatchUpHours);
    $dailyWindows = $planner->dailyWindows($now, $dailyLagMinutes, $dailyCatchUpDays);

    $stats = [
        'servers_considered' => 0,
        'servers_skipped' => 0,
        'hourly_planned' => 0,
        'hourly_aggregated' => 0,
        'hourly_empty' => 0,
        'hourly_failed' => 0,
        'daily_planned' => 0,
        'daily_aggregated' => 0,
        'daily_empty' => 0,
        'daily_failed' => 0,
    ];

    foreach ($monitoringSettings['servers'] ?? [] as $serverName => $serverConfig) {
        if (!is_array($serverConfig) || str_starts_with((string) $serverName, '_') || !isServerActive($serverConfig)) {
            ++$stats['servers_skipped'];

            continue;
        }

        ++$stats['servers_considered'];
        foreach ($hourlyWindows as $hourStart) {
            ++$stats['hourly_planned'];
            $cron->touchHeartbeat();

            try {
                if ($aggregator->aggregateHour((string) $serverName, $hourStart)) {
                    ++$stats['hourly_aggregated'];
                } else {
                    ++$stats['hourly_empty'];
                }
            } catch (Throwable $exception) {
                ++$stats['hourly_failed'];
                $log->error('aggregate_server_metrics.hour_failed', [
                    'server' => $serverName,
                    'hour' => $hourStart->format(DateTimeInterface::ATOM),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        // Daily windows are planned independently. A current hourly aggregate must
        // never prevent a delayed or missed daily rollup from being repaired.
        foreach ($dailyWindows as $dayStart) {
            ++$stats['daily_planned'];
            $cron->touchHeartbeat();

            try {
                if ($aggregator->aggregateDay((string) $serverName, $dayStart)) {
                    ++$stats['daily_aggregated'];
                } else {
                    ++$stats['daily_empty'];
                }
            } catch (Throwable $exception) {
                ++$stats['daily_failed'];
                $log->error('aggregate_server_metrics.daily_failed', [
                    'server' => $serverName,
                    'date' => $dayStart->format('Y-m-d'),
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    $duration = $cron->endTimer();
    $cron->log(sprintf(
        'Finished in %.3fs (memory: %s) | servers=%d hourly=%d/%d daily=%d/%d failed=%d',
        $duration,
        $cron->memoryUsage(),
        $stats['servers_considered'],
        $stats['hourly_aggregated'],
        $stats['hourly_planned'],
        $stats['daily_aggregated'],
        $stats['daily_planned'],
        $stats['hourly_failed'] + $stats['daily_failed']
    ));
    $log->info('aggregate_server_metrics.summary', [
        'duration_seconds' => $duration,
        'memory' => $cron->memoryUsage(),
        'stats' => $stats,
        'timezone' => 'UTC',
        'oldest_hourly_window' => $hourlyWindows[0]->format(DateTimeInterface::ATOM),
        'oldest_daily_window' => $dailyWindows[0]->format('Y-m-d'),
    ]);
    $cron->touchHeartbeat();
} catch (Throwable $exception) {
    $exitCode = 1;
    $cron->log('ERROR: ' . $exception->getMessage());
    $log->critical('aggregate_server_metrics.unhandled_exception', ['error' => $exception->getMessage()]);
} finally {
    $cron->releaseLock();
}

exit($exitCode);

/** @param array<string,mixed> $config */
function isServerActive(array $config): bool
{
    if ((normalizeFlag($config['disabled'] ?? null) ?? false) === true) {
        return false;
    }

    foreach (['enabled', 'active', 'monitoring_enabled'] as $flag) {
        if (normalizeFlag($config[$flag] ?? null) === false) {
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
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    return match (strtolower(trim($value))) {
        '1', 'true', 'yes', 'on', 'enabled' => true,
        '0', 'false', 'no', 'off', 'disabled' => false,
        default => null,
    };
}

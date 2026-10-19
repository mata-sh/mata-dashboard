#!/usr/bin/php8.2
<?php

declare(strict_types=1);

use Mata\Bootstrap\CronRuntime;
use Mata\Bootstrap\MonitoringRuntime;
use Mata\Config\Monitoring\ServerConfigState;
use Mata\Models\ServerMetrics\ServerMetricsRepository;
use Mata\Services\ServerMetrics\AggregationWindowPlanner;
use Mata\Services\ServerMetrics\ServerMetricsAggregator;

require_once __DIR__ . '/../vendor/autoload.php';

$runtime = CronRuntime::boot(__FILE__);
$runtime->requireDatabase();
$monitoringRuntime = MonitoringRuntime::build(
    paths: $runtime->paths,
    appConfig: $runtime->appConfig,
    log: $runtime->log,
    db: $runtime->db,
);

$log = $runtime->log;
$cron = $runtime->cron;
$db = $runtime->db;
$configSyncRepository = $monitoringRuntime->configSyncRepository;
$idResolver = $monitoringRuntime->idResolver;
$monitoringConfig = $monitoringRuntime->monitoringConfig;

if (!$cron->acquireLock()) {
    exit("Already running.\n");
}

$cron->startTimer();
$cron->log('Started');
$exitCode = 0;

try {
    $aggregationConfig = $monitoringConfig->dataRetention->aggregation;
    $hourlyLagMinutes = $aggregationConfig['hourly']['lag_minutes'];
    $hourlyCatchUpHours = $aggregationConfig['hourly']['catch_up_hours'];
    $dailyLagMinutes = $aggregationConfig['daily']['lag_minutes'];
    $dailyCatchUpDays = $aggregationConfig['daily']['catch_up_days'];

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

    foreach ($monitoringConfig->servers->values as $serverName => $serverConfig) {
        if (!is_array($serverConfig) || str_starts_with((string) $serverName, '_') || ServerConfigState::isDisabled($serverConfig)) {
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

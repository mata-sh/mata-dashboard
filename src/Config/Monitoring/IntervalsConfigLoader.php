<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring;

use Mata\Services\Event\EventRecorderService;
use RuntimeException;

final class IntervalsConfigLoader extends AbstractJsonConfigLoader implements MonitoringConfigLoaderInterface
{
    public function __construct(
        private EventRecorderService $eventRecorder,
        private bool $fallbackOnFailure = true,
    ) {}

    public function key(): string
    {
        return 'intervals';
    }

    public function load(string $configRoot): array
    {
        return $this->loadIntervalsConfig(rtrim($configRoot, '/') . '/intervals.json');
    }

    /** @return array<string,mixed> */
    public function loadIntervalsConfig(string $filePath): array
    {
        try {
            $config = $this->normalizeJson($this->readJsonObject($filePath));
        } catch (RuntimeException $exception) {
            $this->reportLoadFailure($filePath);

            throw new MonitoringConfigLoadException('intervals.json invalid or empty', previous: $exception);
        }
        if ($config === []) {
            $this->reportLoadFailure($filePath);

            throw new MonitoringConfigLoadException('intervals.json invalid or empty');
        }

        return array_replace_recursive($this->getDefaultConfig(), $config);
    }

    public function shouldFallback(): bool
    {
        return $this->fallbackOnFailure;
    }

    /** @return array<string,mixed> */
    public function getDefaultConfig(): array
    {
        return [
            'events' => [
                'min_event_interval_minutes' => [
                    '_default' => 60,
                    'app_unreachable' => 0,
                    'server_apps_transport_unreachable' => 0,
                    'cronjobs_failing' => 30,
                    'filecount_too_large' => 72,
                    'logfile_too_large' => 12,
                    'too_many_sql_connections' => 72,
                    'remaining_diskspace_too_low' => 60,
                    'server_metrics.ingestion_run_empty' => 5,
                    'server_metrics.ingestion_stalled' => 15,
                    'cpu_usage_too_high' => 30,
                    'ram_usage_too_high' => 30,
                    'fatal_errors_occurring' => 0,
                ],
            ],
            'alarms' => [
                'min_alarm_interval_minutes' => [
                    '_default' => 1440,
                    'app_unreachable' => 60,
                    'server_apps_transport_unreachable' => 60,
                    'cronjobs_failing' => 360,
                    'filecount_too_large' => 60,
                    'logfile_too_large' => 720,
                    'too_many_sql_connections' => 60,
                    'remaining_diskspace_too_low' => 180,
                    'server_metrics.ingestion_run_empty' => 30,
                    'server_metrics.ingestion_stalled' => 60,
                    'cpu_usage_too_high' => 180,
                    'ram_usage_too_high' => 180,
                    'fatal_errors_occurring' => 1440,
                ],
            ],
        ];
    }

    private function reportLoadFailure(string $filePath): void
    {
        $this->eventRecorder->recordEvent(
            type: 'intervals_config_not_found',
            message: "Could not load {$filePath} -> fallback to Default Values",
            level: 'warning',
        );
    }
}

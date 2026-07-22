<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring;

use Mata\Services\Event\EventRecorderService;
use RuntimeException;

final class DataRetentionConfigLoader extends AbstractJsonConfigLoader implements MonitoringConfigLoaderInterface
{
    public function __construct(
        private EventRecorderService $eventRecorder,
        private bool $fallbackOnFailure = true,
    ) {}

    public function key(): string
    {
        return 'data_retention';
    }

    public function load(string $configRoot): array
    {
        return $this->loadDataRetentionConfig(rtrim($configRoot, '/') . '/data_retention.json');
    }

    /** @return array<string,mixed> */
    public function loadDataRetentionConfig(string $filePath): array
    {
        try {
            $config = $this->normalizeJson($this->readJsonObject($filePath));
        } catch (RuntimeException $exception) {
            $this->reportLoadFailure($filePath);

            throw new MonitoringConfigLoadException('data_retention.json invalid or empty', previous: $exception);
        }
        if ($config === []) {
            $this->reportLoadFailure($filePath);

            throw new MonitoringConfigLoadException('data_retention.json invalid or empty');
        }

        return $this->normalizeConfig(array_replace_recursive($this->getDefaultConfig(), $config));
    }

    public function shouldFallback(): bool
    {
        return $this->fallbackOnFailure;
    }

    /** @return array<string,mixed> */
    public function getDefaultConfig(): array
    {
        return [
            'metrics' => [
                'raw_retention_days' => 30,
                'hourly_retention_days' => 90,
                'daily_retention_days' => 365,
            ],
            'events' => ['retention_days' => 180],
            'alarms' => ['retention_days' => 365],
            'aggregation' => [
                'hourly' => ['lag_minutes' => 5, 'catch_up_hours' => 24],
                'daily' => ['lag_minutes' => 30],
            ],
            'pruning' => ['batch_limit' => 5000],
            'ingestion_health' => [
                'stall_minutes' => 15,
                'log_rotate_megabytes' => 5,
                'log_rotate_files' => 3,
            ],
        ];
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    private function normalizeConfig(array $config): array
    {
        $defaults = $this->getDefaultConfig();
        foreach ([
            ['metrics', 'raw_retention_days'],
            ['metrics', 'hourly_retention_days'],
            ['metrics', 'daily_retention_days'],
            ['events', 'retention_days'],
            ['alarms', 'retention_days'],
        ] as [$section, $key]) {
            if (!is_array($config[$section] ?? null)) {
                $config[$section] = $defaults[$section];
            }
            $config[$section][$key] = max(0, (int) ($config[$section][$key] ?? 0));
        }

        return $config;
    }

    private function reportLoadFailure(string $filePath): void
    {
        $this->eventRecorder->recordEvent(
            type: 'data_retention_config_not_found',
            message: "Could not load {$filePath} -> fallback to Default Values",
            level: 'warning',
        );
    }
}

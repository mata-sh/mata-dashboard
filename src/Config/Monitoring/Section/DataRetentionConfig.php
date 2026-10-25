<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Section;

use Mata\Config\ConfigObject;

/** Retention, aggregation, pruning, and ingestion-health configuration. */
final readonly class DataRetentionConfig
{
    /**
     * @param array<string,int>   $metrics
     * @param array<string,int>   $events
     * @param array<string,int>   $alarms
     * @param array<string,mixed> $aggregation
     * @param array<string,mixed> $pruning
     * @param array<string,mixed> $ingestionHealth
     */
    private function __construct(
        public array $metrics,
        public array $events,
        public array $alarms,
        public array $aggregation,
        public array $pruning,
        public array $ingestionHealth,
    ) {}

    public static function empty(): self
    {
        return self::fromArray([
            'metrics' => [
                'raw_retention_days' => 0,
                'hourly_retention_days' => 0,
                'daily_retention_days' => 0,
            ],
            'events' => ['retention_days' => 0],
            'alarms' => ['retention_days' => 0],
        ]);
    }

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $retention = ConfigObject::fromValue($raw, 'data_retention');
        $metrics = $retention->object('metrics');
        $aggregation = $retention->object('aggregation');
        $hourly = $aggregation->object('hourly');
        $daily = $aggregation->object('daily');
        $ingestionHealth = $retention->object('ingestion_health');

        return new self(
            metrics: [
                'raw_retention_days' => $metrics->integer('raw_retention_days', 30, minimum: 0),
                'hourly_retention_days' => $metrics->integer('hourly_retention_days', 90, minimum: 0),
                'daily_retention_days' => $metrics->integer('daily_retention_days', 365, minimum: 0),
            ],
            events: ['retention_days' => $retention->object('events')->integer('retention_days', 180, minimum: 0)],
            alarms: ['retention_days' => $retention->object('alarms')->integer('retention_days', 365, minimum: 0)],
            aggregation: [
                'hourly' => [
                    'lag_minutes' => $hourly->integer('lag_minutes', 5, minimum: 0),
                    'catch_up_hours' => $hourly->integer('catch_up_hours', 24, minimum: 1),
                ],
                'daily' => [
                    'lag_minutes' => $daily->integer('lag_minutes', 30, minimum: 0),
                    'catch_up_days' => $daily->integer('catch_up_days', 7, minimum: 1),
                ],
            ],
            pruning: ['batch_limit' => $retention->object('pruning')->integer('batch_limit', 5000, 1, 100000)],
            ingestionHealth: [
                'stall_minutes' => $ingestionHealth->integer('stall_minutes', 15, minimum: 5),
                'log_rotate_megabytes' => $ingestionHealth->integer('log_rotate_megabytes', 5, minimum: 1),
                'log_rotate_files' => $ingestionHealth->integer('log_rotate_files', 3, minimum: 1),
            ],
        );
    }

    public static function default(): self
    {
        return self::fromArray([]);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'metrics' => $this->metrics,
            'events' => $this->events,
            'alarms' => $this->alarms,
            'aggregation' => $this->aggregation,
            'pruning' => $this->pruning,
            'ingestion_health' => $this->ingestionHealth,
        ];
    }
}

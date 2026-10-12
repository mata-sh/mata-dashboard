<?php

declare(strict_types=1);

namespace Mata\Config\Monitoring\Section;

use InvalidArgumentException;
use Mata\Config\ConfigValueValidator;

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
        foreach (['metrics', 'events', 'alarms', 'aggregation', 'pruning', 'ingestion_health'] as $section) {
            if (array_key_exists($section, $raw)) {
                ConfigValueValidator::object($raw[$section], "data_retention.{$section}");
            }
        }
        if (is_array($raw['aggregation'] ?? null)) {
            foreach (['hourly', 'daily'] as $period) {
                if (array_key_exists($period, $raw['aggregation'])) {
                    ConfigValueValidator::object($raw['aggregation'][$period], "data_retention.aggregation.{$period}");
                }
            }
        }

        $config = array_replace_recursive(self::defaults(), $raw);

        $metrics = [
            'raw_retention_days' => self::integer($config, ['metrics', 'raw_retention_days'], 0),
            'hourly_retention_days' => self::integer($config, ['metrics', 'hourly_retention_days'], 0),
            'daily_retention_days' => self::integer($config, ['metrics', 'daily_retention_days'], 0),
        ];
        $events = ['retention_days' => self::integer($config, ['events', 'retention_days'], 0)];
        $alarms = ['retention_days' => self::integer($config, ['alarms', 'retention_days'], 0)];
        $aggregation = [
            'hourly' => [
                'lag_minutes' => self::integer($config, ['aggregation', 'hourly', 'lag_minutes'], 0),
                'catch_up_hours' => self::integer($config, ['aggregation', 'hourly', 'catch_up_hours'], 1),
            ],
            'daily' => [
                'lag_minutes' => self::integer($config, ['aggregation', 'daily', 'lag_minutes'], 0),
                'catch_up_days' => self::integer($config, ['aggregation', 'daily', 'catch_up_days'], 1),
            ],
        ];
        $pruning = [
            'batch_limit' => self::integer($config, ['pruning', 'batch_limit'], 1, 100000),
        ];
        $ingestionHealth = [
            'stall_minutes' => self::integer($config, ['ingestion_health', 'stall_minutes'], 5),
            'log_rotate_megabytes' => self::integer($config, ['ingestion_health', 'log_rotate_megabytes'], 1),
            'log_rotate_files' => self::integer($config, ['ingestion_health', 'log_rotate_files'], 1),
        ];

        return new self($metrics, $events, $alarms, $aggregation, $pruning, $ingestionHealth);
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

    /** @return array<string,mixed> */
    public static function defaults(): array
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
                'daily' => ['lag_minutes' => 30, 'catch_up_days' => 7],
            ],
            'pruning' => ['batch_limit' => 5000],
            'ingestion_health' => [
                'stall_minutes' => 15,
                'log_rotate_megabytes' => 5,
                'log_rotate_files' => 3,
            ],
        ];
    }

    /** @param array<string,mixed> $config
     * @param non-empty-list<string> $segments
     */
    private static function integer(
        array $config,
        array $segments,
        ?int $minimum = null,
        ?int $maximum = null,
    ): int {
        $value = $config;
        foreach ($segments as $segment) {
            $value = is_array($value) && array_key_exists($segment, $value) ? $value[$segment] : null;
        }

        return ConfigValueValidator::integer(
            $value,
            'data_retention.' . implode('.', $segments),
            $minimum,
            $maximum,
        );
    }
}

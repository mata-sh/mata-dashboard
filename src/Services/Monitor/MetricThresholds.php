<?php

declare(strict_types=1);

namespace Mata\Services\Monitor;

use Mata\Config\Monitoring\Section\ThresholdsConfig;

/**
 * The metric limits from thresholds.json (`server_data`, `app_data`): which configured level a
 * value exceeds, and the limit of one level.
 */
final readonly class MetricThresholds
{
    /**
     * @param array<string,array<string,float|int>> $serverData limits by metric, then level, in ascending order
     * @param array<string,array<string,float|int>> $appData
     */
    public function __construct(
        private array $serverData,
        private array $appData,
    ) {}

    public static function fromConfig(ThresholdsConfig $config): self
    {
        return new self($config->serverData, $config->appData);
    }

    /** @return string the highest level whose limit the value exceeds (e.g. warning, alarm), or '' for none */
    public function serverLevel(int $value, string $metric): string
    {
        return self::levelOf($this->serverData[$metric] ?? [], $value);
    }

    /** @return null|float|int null when the metric has no such level */
    public function serverLimit(string $metric, string $level = 'alarm'): float|int|null
    {
        return $this->serverData[$metric][$level] ?? null;
    }

    /** @return string the highest level whose limit the value exceeds, or '' for none */
    public function appLevel(int $value, string $metric): string
    {
        return self::levelOf($this->appData[$metric] ?? [], $value);
    }

    /** @return null|float|int null when the metric has no such level */
    public function appLimit(string $metric, string $level): float|int|null
    {
        return $this->appData[$metric][$level] ?? null;
    }

    /** @param array<string,float|int> $limits */
    private static function levelOf(array $limits, int $value): string
    {
        $result = '';
        foreach ($limits as $level => $limit) {
            if ($value > $limit) {
                $result = $level;
            }
        }

        return $result;
    }
}

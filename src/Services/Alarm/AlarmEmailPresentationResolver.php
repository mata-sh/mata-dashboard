<?php

declare(strict_types=1);

namespace Mata\Services\Alarm;

use Mata\Services\Alarm\Dto\Alarm;

/** Resolves alarm-type-specific content for the alarm email metric card. */
final class AlarmEmailPresentationResolver
{
    /** @param array<string,mixed> $thresholds */
    public function __construct(private array $thresholds = []) {}

    /**
     * @return array{description:string,metric:null|array{label:string,value:string,threshold:?string}}
     */
    public function resolve(Alarm $alarm): array
    {
        return match (strtolower(trim($alarm->type))) {
            'fatal_errors_occurring' => $this->fatalErrors($alarm->message),
            'remaining_diskspace_too_low' => $this->diskUsage($alarm->message),
            'cronjobs_failing' => $this->cronjobs($alarm->message),
            'node_unhealthy' => [
                'description' => 'Detected an unhealthy health response.',
                'metric' => ['label' => 'NODE_UNHEALTHY', 'value' => 'UNHEALTHY', 'threshold' => null],
            ],
            'node_unreachable' => [
                'description' => 'Detected an unreachable server health endpoint.',
                'metric' => ['label' => 'NODE_UNREACHABLE', 'value' => 'UNREACHABLE', 'threshold' => null],
            ],
            default => [
                'description' => $this->fallbackDescription($alarm->type),
                'metric' => null,
            ],
        };
    }

    /** @return array{description:string,metric:array{label:string,value:string,threshold:null}} */
    private function fatalErrors(string $message): array
    {
        preg_match('/\bCount:\s*([^\)]+)/i', $message, $matches);
        $value = isset($matches[1]) ? trim($matches[1]) : 'UNKNOWN';

        return [
            'description' => 'Detected fatal errors above threshold.',
            'metric' => [
                'label' => 'FATAL_ERRORS_OCCURRING',
                'value' => $value === '' ? 'UNKNOWN' : $value,
                'threshold' => null,
            ],
        ];
    }

    /** @return array{description:string,metric:array{label:string,value:string,threshold:?string}} */
    private function diskUsage(string $message): array
    {
        preg_match('/\(([0-9]+(?:\.[0-9]+)?)\s*%\s*used\)/i', $message, $matches);
        $value = isset($matches[1]) ? $this->formatPercent($matches[1]) : 'UNKNOWN';

        return [
            'description' => 'Detected disk usage above threshold.',
            'metric' => [
                'label' => 'REMAINING_DISKSPACE_TOO_LOW',
                'value' => $value,
                'threshold' => $this->threshold('server_data', 'disk_space_usage_percent', '%'),
            ],
        ];
    }

    /** @return array{description:string,metric:array{label:string,value:string,threshold:?string}} */
    private function cronjobs(string $message): array
    {
        preg_match('/\(([0-9]+)\/[0-9]+(?:,|\))/i', $message, $matches);
        $failed = isset($matches[1]) ? (int) $matches[1] : null;

        return [
            'description' => 'Detected failing cronjobs.',
            'metric' => [
                'label' => 'CRONJOBS_FAILING',
                'value' => $failed === null ? 'UNKNOWN' : $this->formatNumber($failed),
                'threshold' => $this->threshold('server_data', 'cronjobs_failed_count'),
            ],
        ];
    }

    private function fallbackDescription(string $type): string
    {
        $type = trim($type);

        return $type === '' ? 'Detected an alarm condition.' : 'Detected ' . str_replace('_', ' ', $type) . '.';
    }

    private function threshold(string $section, string $key, string $suffix = ''): ?string
    {
        $value = $this->thresholds[$section][$key]['alarm'] ?? null;
        if (!is_numeric($value)) {
            return null;
        }

        return 'threshold: ' . $this->formatNumber((float) $value) . $suffix;
    }

    private function formatPercent(string $value): string
    {
        $number = (float) $value;
        $formatted = floor($number) === $number
            ? $this->formatNumber($number)
            : rtrim(rtrim(number_format($number, 2, ',', '.'), '0'), ',');

        return $formatted . '%';
    }

    private function formatNumber(float|int $number): string
    {
        return number_format($number, 0, '', '.');
    }
}

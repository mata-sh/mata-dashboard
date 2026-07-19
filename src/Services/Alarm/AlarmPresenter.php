<?php

declare(strict_types=1);

namespace Mata\Services\Alarm;

use Mata\Services\Alarm\Dto\Alarm;
use Mata\Services\Alarm\Dto\AlarmEnvironment;
use Mata\Services\Alarm\Dto\AlarmMetric;
use Mata\Services\Alarm\Dto\AlarmPresentation;

/** Builds the presentation of an alarm for alarm emails: description, metric card, server color and environment. */
final class AlarmPresenter
{
    /**
     * @param array<string,mixed> $thresholds
     * @param array<string,mixed> $servers
     */
    public function __construct(
        private array $thresholds = [],
        private array $servers = [],
    ) {}

    public function present(Alarm $alarm, string $server, ?string $appEnvironment): AlarmPresentation
    {
        $type = strtolower(trim($alarm->type));

        // Each arm yields [description, metric value, metric threshold].
        [$description, $value, $threshold] = match ($type) {
            'fatal_errors_occurring' => [
                'Detected fatal errors above threshold.',
                $this->fatalErrorCount($alarm->message),
                null,
            ],
            'remaining_diskspace_too_low' => [
                'Detected disk usage above threshold.',
                $this->diskUsagePercent($alarm->message),
                $this->threshold('server_data', 'disk_space_usage_percent', '%'),
            ],
            'cronjobs_failing' => [
                'Detected failing cronjobs.',
                $this->failedCronjobCount($alarm->message),
                $this->threshold('server_data', 'cronjobs_failed_count'),
            ],
            'node_unhealthy' => [
                'Detected an unhealthy health response.',
                'UNHEALTHY',
                null
            ],
            'node_unreachable' => [
                'Detected an unreachable server health endpoint.',
                'UNREACHABLE',
                null
            ],
            default => [
                $this->fallbackDescription($alarm->type),
                null,
                null
            ],
        };

        $config = $this->serverConfig($server);
        $serverEnv = is_string($config['env'] ?? null) ? $config['env'] : null;

        return new AlarmPresentation(
            $description,
            $value === null ? null : new AlarmMetric(strtoupper($type), $value, $threshold),
            $this->serverColor($config['primary_color'] ?? null),
            AlarmEnvironment::tryFromString($appEnvironment) ?? AlarmEnvironment::tryFromString($serverEnv),
        );
    }

    private function fatalErrorCount(string $message): string
    {
        preg_match('/\bCount:\s*([^\)]+)/i', $message, $matches);
        $value = isset($matches[1]) ? trim($matches[1]) : '';

        return $value === '' ? 'UNKNOWN' : $value;
    }

    private function diskUsagePercent(string $message): string
    {
        preg_match('/\(([0-9]+(?:\.[0-9]+)?)\s*%\s*used\)/i', $message, $matches);

        return isset($matches[1]) ? $this->formatPercent($matches[1]) : 'UNKNOWN';
    }

    private function failedCronjobCount(string $message): string
    {
        preg_match('/\(([0-9]+)\/[0-9]+(?:,|\))/i', $message, $matches);

        return isset($matches[1]) ? $this->formatNumber((int) $matches[1]) : 'UNKNOWN';
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

    /** @return array<string,mixed> */
    private function serverConfig(string $server): array
    {
        foreach ($this->servers as $name => $config) {
            if (is_array($config) && strcasecmp((string) $name, $server) === 0) {
                return $config;
            }
        }

        return [];
    }

    private function serverColor(mixed $color): ?string
    {
        if (!is_string($color) || preg_match('/^#[0-9a-f]{6}$/i', $color) !== 1) {
            return null;
        }

        return $color;
    }
}

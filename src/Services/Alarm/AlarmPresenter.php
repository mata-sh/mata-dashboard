<?php

declare(strict_types=1);

namespace Mata\Services\Alarm;

use Mata\Services\Alarm\Dto\Alarm;
use Mata\Services\Alarm\Dto\AlarmEnvironment;
use Mata\Services\Alarm\Dto\AlarmMetric;
use Mata\Services\Alarm\Dto\AlarmPresentation;
use Mata\Utils\NumberFormatter;

/** Builds the presentation of an alarm for alarm emails: description, metric card, server color and environment. */
final class AlarmPresenter
{
    /**
     * @param array<string,mixed> $servers
     */
    public function __construct(
        private array $servers = [],
        private NumberFormatter $numberFormatter = new NumberFormatter(),
    ) {}

    public function present(Alarm $alarm, string $server, ?string $appEnvironment): AlarmPresentation
    {
        $type = strtolower(trim($alarm->type));
        $decodedContext = is_string($alarm->context) ? json_decode($alarm->context, true) : null;

        /** @var array<string,mixed> $context */
        $context = is_array($decodedContext) ? $decodedContext : [];

        // Each arm yields [description, metric value, metric threshold].
        [$description, $value, $threshold] = match ($type) {
            'fatal_errors_occurring' => [
                'Detected fatal errors above threshold.',
                is_numeric($context['actual_value'] ?? null) ? $this->numberFormatter->formatCompact($context['actual_value']) : 'UNKNOWN',
                null,
            ],
            'remaining_diskspace_too_low' => [
                'Detected disk usage above threshold.',
                is_numeric($context['actual_value'] ?? null) ? $this->formatPercent((float) $context['actual_value']) : 'UNKNOWN',
                $this->thresholdLabel($context, '%'),
            ],
            'cronjobs_failing' => [
                'Detected failing cronjobs.',
                is_numeric($context['actual_value'] ?? null) ? $this->formatNumber((int) $context['actual_value']) : 'UNKNOWN',
                $this->thresholdLabel($context),
            ],
            'node_unhealthy' => [
                'Detected an unhealthy health response.',
                'UNHEALTHY',
                null,
            ],
            'node_unreachable' => [
                'Detected an unreachable server health endpoint.',
                'UNREACHABLE',
                null,
            ],
            default => [
                $this->fallbackDescription($alarm->type),
                null,
                null,
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

    private function fallbackDescription(string $type): string
    {
        $type = trim($type);

        return $type === '' ? 'Detected an alarm condition.' : 'Detected ' . str_replace('_', ' ', $type) . '.';
    }

    /**
     * @param array<string,mixed> $context
     */
    private function thresholdLabel(array $context, string $suffix = ''): ?string
    {
        $value = $context['threshold'] ?? null;
        if (!is_numeric($value)) {
            return null;
        }

        return 'threshold: ' . $this->formatNumber((float) $value) . $suffix;
    }

    private function formatPercent(float $value): string
    {
        $formatted = floor($value) === $value
            ? $this->formatNumber($value)
            : rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');

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

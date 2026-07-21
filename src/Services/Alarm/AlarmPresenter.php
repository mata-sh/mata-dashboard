<?php

declare(strict_types=1);

namespace Mata\Services\Alarm;

use Mata\Services\Alarm\Dto\Alarm;
use Mata\Services\Alarm\Dto\AlarmEnvironment;
use Mata\Services\Alarm\Dto\AlarmMetric;
use Mata\Services\Alarm\Dto\AlarmPresentation;
use Mata\Utils\NumberFormatter;

/** Builds the presentation of an alarm for alarm emails. */
final class AlarmPresenter
{
    /** @param array<string,mixed> $servers */
    public function __construct(private array $servers = [], private NumberFormatter $numberFormatter = new NumberFormatter()) {}

    public function present(Alarm $alarm, string $server, ?string $appEnvironment): AlarmPresentation
    {
        $type = strtolower(trim($alarm->type));
        $decodedContext = is_string($alarm->context) ? json_decode($alarm->context, true) : null;

        /** @var array<string,mixed> $context */
        $context = is_array($decodedContext) ? $decodedContext : [];
        $ruleDetail = $this->ruleDetail($alarm->triggerEvidence, $type);

        [$description, $value, $detail] = match ($type) {
            'fatal_errors_occurring' => ['Detected fatal errors above threshold.', is_numeric($context['actual_value'] ?? null) ? $this->numberFormatter->formatCompact($context['actual_value']) : 'UNKNOWN', $ruleDetail],
            'remaining_diskspace_too_low' => ['Detected disk usage above threshold.', is_numeric($context['actual_value'] ?? null) ? $this->formatPercent((float) $context['actual_value']) : 'UNKNOWN', $this->metricThresholdLabel($context, '%') ?? $ruleDetail],
            'cronjobs_failing' => ['Detected failing cronjobs.', is_numeric($context['actual_value'] ?? null) ? $this->formatNumber((int) $context['actual_value']) : 'UNKNOWN', $ruleDetail],
            'cpu_usage_too_high', 'ram_usage_too_high' => ['Detected elevated server resource usage.', is_numeric($context['actual_value'] ?? null) ? $this->formatPercent((float) $context['actual_value']) : 'UNKNOWN', $ruleDetail],
            'server_metrics.ingestion_run_empty' => ['Detected an empty metrics ingestion run.', 'EMPTY', $ruleDetail],
            'node_unhealthy' => ['Detected an unhealthy health response.', 'UNHEALTHY', $ruleDetail],
            'node_unreachable' => ['Detected an unreachable server health endpoint.', 'UNREACHABLE', $ruleDetail ?? 'Alarm rule: first failed check'],
            'app_unreachable' => ['Detected an unreachable application endpoint.', 'UNREACHABLE', $ruleDetail],
            default => [$this->fallbackDescription($alarm->type), null, null],
        };

        $config = $this->serverConfig($server);
        $serverEnv = is_string($config['env'] ?? null) ? $config['env'] : null;

        return new AlarmPresentation($description, $value === null ? null : new AlarmMetric(strtoupper($type), $value, $detail), $this->serverColor($config['primary_color'] ?? null), AlarmEnvironment::tryFromString($appEnvironment) ?? AlarmEnvironment::tryFromString($serverEnv));
    }

    /** @param array<string,mixed> $evidence */
    private function ruleDetail(array $evidence, string $type): ?string
    {
        if ($type === 'node_unreachable' && (($evidence['strategy'] ?? null) === 'immediate' || $evidence === [])) {
            return 'Alarm rule: first failed check';
        }
        $label = trim((string) ($evidence['label'] ?? ''));
        if ($label !== '') {
            return 'Alarm rule: ' . $label;
        }
        if ($type === 'node_unreachable') {
            return 'Alarm rule: first failed check';
        }

        return $evidence !== [] ? 'Alarm rule: first event' : null;
    }

    private function fallbackDescription(string $type): string
    {
        $type = trim($type);

        return $type === '' ? 'Detected an alarm condition.' : 'Detected ' . str_replace('_', ' ', $type) . '.';
    }

    /** @param array<string,mixed> $context */
    private function metricThresholdLabel(array $context, string $suffix = ''): ?string
    {
        $value = $context['threshold'] ?? null;

        return is_numeric($value) ? 'Threshold: ' . $this->formatNumber((float) $value) . $suffix : null;
    }

    private function formatPercent(float $value): string
    {
        $formatted = floor($value) === $value ? $this->formatNumber($value) : rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');

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
        return is_string($color) && preg_match('/^#[0-9a-f]{6}$/i', $color) === 1 ? $color : null;
    }
}

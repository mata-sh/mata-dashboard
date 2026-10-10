<?php

declare(strict_types=1);

namespace Mata\Services\Alarm\Delivery;

use Mata\Services\Alarm\Delivery\Dto\AlarmEnvironment;
use Mata\Services\Alarm\Delivery\Dto\AlarmMetric;
use Mata\Services\Alarm\Delivery\Dto\AlarmPresentation;
use Mata\Services\Alarm\Dto\Alarm;
use Mata\Views\Support\NumberFormatter;

/**
 * Describes an alarm by its type, for every channel: description, metric, and the target's
 * server color and environment from servers.json.
 */
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

        $value = $this->metricValue($type, $context['actual_value'] ?? null);
        [$description, $detail] = match ($type) {
            'fatal_errors_occurring' => ['Detected fatal errors above threshold.', $ruleDetail],
            'remaining_diskspace_too_low' => ['Detected disk usage above threshold.', $this->thresholdDetail($context, '%') ?? $ruleDetail],
            'cronjobs_failing' => ['Detected failing cronjobs.', $ruleDetail],
            'cpu_usage_too_high', 'ram_usage_too_high' => ['Detected elevated server resource usage.', $ruleDetail],
            'database_connections_too_high' => ['Detected database connections near max_connections.', $this->thresholdDetail($context, '%') ?? $ruleDetail],
            'database_unreachable' => ['Detected an unreachable database server.', $ruleDetail],
            'server_metrics.ingestion_run_empty' => ['Detected an empty metrics ingestion run.', $ruleDetail],
            'node_unhealthy' => ['Detected an unhealthy health response.', $ruleDetail],
            'node_unreachable' => ['Detected an unreachable server health endpoint.', $ruleDetail ?? 'Alarm rule: first failed check'],
            'app_unreachable' => ['Detected an unreachable application endpoint.', $ruleDetail],
            'server_apps_transport_unreachable' => ['Detected a transport failure affecting all application endpoints.', $ruleDetail],
            'users_file_insecure' => ['Detected insecure permissions on the users file.', $ruleDetail],
            'library_outdated_security' => ['Detected Composer libraries with new security advisories.', $ruleDetail],
            'user_account_locked' => ['Detected an account locked after repeated failed logins.', $ruleDetail],
            'user_account_changed' => ['Detected a created, deleted or re-roled user account.', $ruleDetail],
            default => [$this->defaultDescription($alarm->type), null],
        };

        $config = $this->serverConfig($server);
        $serverEnv = is_string($config['env'] ?? null) ? $config['env'] : null;

        $metric = $value === null ? null : new AlarmMetric(strtoupper($type), $value, $detail);
        $environment = AlarmEnvironment::tryFromString($appEnvironment) ?? AlarmEnvironment::tryFromString($serverEnv);

        return new AlarmPresentation(
            description: $description,
            metric: $metric,
            serverColor: $this->serverColor($config['primary_color'] ?? null),
            environment: $environment,
        );
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

    private function defaultDescription(string $type): string
    {
        $type = trim($type);

        return $type === '' ? 'Detected an alarm condition.' : 'Detected ' . str_replace('_', ' ', $type) . '.';
    }

    /** @param array<string,mixed> $context */
    private function thresholdDetail(array $context, string $suffix = ''): ?string
    {
        $value = $context['threshold'] ?? null;

        return is_numeric($value) ? 'Threshold: ' . $this->formatNumber((float) $value) . $suffix : null;
    }

    private function metricValue(string $type, mixed $actualValue): ?string
    {
        return match ($type) {
            'fatal_errors_occurring' => $this->formatMetricValue($actualValue, $this->numberFormatter->formatCompact(...)),
            'remaining_diskspace_too_low', 'cpu_usage_too_high', 'ram_usage_too_high', 'database_connections_too_high' => $this->formatMetricValue($actualValue, $this->formatPercent(...)),
            'cronjobs_failing', 'library_outdated_security' => $this->formatMetricValue($actualValue, $this->formatNumber(...)),
            'server_metrics.ingestion_run_empty' => 'EMPTY',
            'node_unhealthy' => 'UNHEALTHY',
            'node_unreachable', 'app_unreachable', 'server_apps_transport_unreachable', 'database_unreachable' => 'UNREACHABLE',
            'users_file_insecure' => 'INSECURE',
            'user_account_locked' => 'LOCKED',
            'user_account_changed' => 'CHANGED',
            default => null,
        };
    }

    private function formatMetricValue(mixed $value, callable $formatter): string
    {
        return is_numeric($value) ? $formatter((float) $value) : 'UNKNOWN';
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
        if (!is_string($color) || preg_match('/^#[0-9a-f]{6}$/i', $color) !== 1) {
            return null;
        }

        return $color;
    }
}

<?php

declare(strict_types=1);

namespace Watcher\Services\Monitor;

use DateTimeImmutable;
use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Watcher\Models\ServerMetrics\ServerMetricsRepository;
use Watcher\Services\Foundation\EventManager;

/**
 * Coordinates ingestion health telemetry for server metrics collection.
 *
 * Responsibilities:
 * - Capture pre/post-run raw sample counters
 * - Detect stalls based on configurable lag thresholds
 * - Rotate health log files and append structured JSON entries
 * - Emit monitoring events for dashboards and alerts
 */
final class ServerMetricsHealthMonitor
{
    public function __construct(
        private readonly ServerMetricsRepository $repository,
        private readonly EventManager $eventManager,
        private readonly LoggerInterface $logger,
        private readonly string $healthLogPath,
        private readonly int $stallThresholdMinutes,
        private readonly int $maxLogBytes,
        private readonly int $retainedFiles
    ) {}

    /**
     * Rotate the health log if it exceeds the configured size limit.
     */
    public function rotateLog(): void
    {
        if ($this->maxLogBytes <= 0 || $this->retainedFiles < 1) {
            return;
        }

        $directory = dirname($this->healthLogPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        if (!file_exists($this->healthLogPath) || filesize($this->healthLogPath) < $this->maxLogBytes) {
            return;
        }

        $oldest = $this->healthLogPath . '.' . $this->retainedFiles;
        if (file_exists($oldest)) {
            @unlink($oldest);
        }

        for ($index = $this->retainedFiles - 1; $index >= 1; --$index) {
            $source = $this->healthLogPath . '.' . $index;
            $destination = $this->healthLogPath . '.' . ($index + 1);
            if (file_exists($source)) {
                @rename($source, $destination);
            }
        }

        if (@rename($this->healthLogPath, $this->healthLogPath . '.1')) {
            $this->logger->info('server_metrics.health_log_rotated', [
                'path' => $this->healthLogPath,
                'max_bytes' => $this->maxLogBytes,
                'retained_files' => $this->retainedFiles,
            ]);
        }
    }

    /**
     * Snapshot current raw sample counts and latest timestamps.
     *
     * @return array{counts: array<string,int>, latest: array<string,DateTimeImmutable>}
     */
    public function snapshot(): array
    {
        return [
            'counts' => $this->repository->getRawSampleCountsByServer(),
            'latest' => $this->repository->getLatestRawSampleCollectedAt(),
        ];
    }

    /**
     * Evaluate ingestion after a collection run and emit health telemetry.
     *
     * @param array<int,string>                                                         $servers  Servers that were processed during the run
     * @param array{counts: array<string,int>, latest: array<string,DateTimeImmutable>} $baseline Pre-run snapshot
     *
     * @return array{
     *     generated_at: string,
     *     total_inserted: int,
     *     per_server: array<string,array{before:int,after:int,inserted:int,last_sample_at:?string,lag_minutes:?int}>,
     *     stalled: array<string,array{lag_minutes:int,last_sample_at:?string}>
     * }
     */
    public function evaluate(array $servers, array $baseline, DateTimeImmutable $now): array
    {
        $afterCounts = $this->repository->getRawSampleCountsByServer();
        $afterLatest = $this->repository->getLatestRawSampleCollectedAt();

        $report = $this->buildReport(
            $servers,
            $baseline['counts'],
            $afterCounts,
            $afterLatest,
            $now
        );

        $this->writeHealthLog($report);
        $this->emitMonitoringEvents($report);

        return $report;
    }

    /**
     * @param array<int,string>               $servers
     * @param array<string,int>               $before
     * @param array<string,int>               $after
     * @param array<string,DateTimeImmutable> $latestAfter
     *
     * @return array{
     *     generated_at: string,
     *     total_inserted: int,
     *     per_server: array<string,array{before:int,after:int,inserted:int,last_sample_at:?string,lag_minutes:?int}>,
     *     stalled: array<string,array{lag_minutes:int,last_sample_at:?string}>
     * }
     */
    private function buildReport(
        array $servers,
        array $before,
        array $after,
        array $latestAfter,
        DateTimeImmutable $now
    ): array {
        $report = [
            'generated_at' => $now->format(DateTimeInterface::ATOM),
            'total_inserted' => 0,
            'per_server' => [],
            'stalled' => [],
        ];

        foreach ($servers as $server) {
            $beforeCount = $before[$server] ?? 0;
            $afterCount = $after[$server] ?? $beforeCount;
            $inserted = max(0, $afterCount - $beforeCount);
            $lastSample = $latestAfter[$server] ?? null;
            $lagMinutes = null;

            if ($lastSample instanceof DateTimeImmutable) {
                $diffSeconds = max(0, $now->getTimestamp() - $lastSample->getTimestamp());
                $lagMinutes = (int) floor($diffSeconds / 60);
            }

            $report['per_server'][$server] = [
                'before' => $beforeCount,
                'after' => $afterCount,
                'inserted' => $inserted,
                'last_sample_at' => $lastSample instanceof DateTimeImmutable
                    ? $lastSample->format(DateTimeInterface::ATOM)
                    : null,
                'lag_minutes' => $lagMinutes,
            ];

            $report['total_inserted'] += $inserted;

            if ($inserted === 0 && $lagMinutes !== null && $lagMinutes >= $this->stallThresholdMinutes) {
                $report['stalled'][$server] = [
                    'lag_minutes' => $lagMinutes,
                    'last_sample_at' => $report['per_server'][$server]['last_sample_at'],
                ];
            }
        }

        return $report;
    }

    /**
     * Persist structured health telemetry to the log file.
     *
     * @param array{
     *     generated_at: string,
     *     total_inserted: int,
     *     per_server: array<string,array{before:int,after:int,inserted:int,last_sample_at:?string,lag_minutes:?int}>,
     *     stalled: array<string,array{lag_minutes:int,last_sample_at:?string}>
     * } $report
     */
    private function writeHealthLog(array $report): void
    {
        $directory = dirname($this->healthLogPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $payload = json_encode($report, JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return;
        }

        @file_put_contents($this->healthLogPath, $payload . PHP_EOL, FILE_APPEND);
    }

    /**
     * Emit monitoring events for dashboards and alerting.
     *
     * @param array{
     *     generated_at: string,
     *     total_inserted: int,
     *     per_server: array<string,array{before:int,after:int,inserted:int,last_sample_at:?string,lag_minutes:?int}>,
     *     stalled: array<string,array{lag_minutes:int,last_sample_at:?string}>
     * } $report
     */
    private function emitMonitoringEvents(array $report): void
    {
        $totalServers = count($report['per_server']);
        $stalledServers = array_keys($report['stalled']);

        $this->eventManager->handleEvent(
            type: 'server_metrics.ingestion',
            message: sprintf(
                'Inserted %d new raw samples across %d servers',
                $report['total_inserted'],
                $totalServers
            ),
            context: [
                'generated_at' => $report['generated_at'],
                'total_inserted' => $report['total_inserted'],
                'stalled_servers' => $stalledServers,
            ],
            level: ($report['total_inserted'] === 0 && $totalServers > 0) ? 'warning' : 'info'
        );

        foreach ($report['stalled'] as $server => $details) {
            $this->eventManager->handleEvent(
                type: 'server_metrics.ingestion_stall',
                message: sprintf(
                    'No new server metrics ingested for %s (lag %d minutes)',
                    $server,
                    $details['lag_minutes']
                ),
                context: $details,
                level: 'warning',
                server: $server
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace Watcher\Utils;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Base class for cron script functionality and management.
 *
 * Provides comprehensive cron job infrastructure:
 * - Process locking to prevent concurrent execution
 * - Heartbeat monitoring for external health checks
 * - Execution timing and performance monitoring
 * - Memory usage tracking
 * - Job-specific logging with timestamps
 * - Configuration file loading and parsing
 * - Error handling and cleanup
 */
class CronJob
{
    /**
     * Human‑readable job identifier.
     * Used as basename for log and lock files.
     */
    protected string $jobName;

    /**
     * Path to heartbeat file that is appended with a timestamp on every heartbeat.
     */
    protected string $heartbeatFile;

    /**
     * Path to lock file that prevents concurrent execution.
     */
    protected string $lockFile;

    /**
     * Timestamp captured by {@see startTimer()} (microtime true).
     */
    protected float $startTime;

    /**
     * Logger for cron job output.
     */
    protected LoggerInterface $logger;

    /**
     * Configuration data injected via {@see setConfig()}.
     *
     * @var array<string,mixed>
     */
    protected array $config;

    /**
     * CronJob constructor.
     *
     * @param string               $jobName       Name of the cronjob like "daily-report"
     * @param string               $lockDir       Directory for lock files
     * @param string               $heartbeatFile File written by touchHeartbeat()
     * @param null|LoggerInterface $logger        Logger instance for job output
     */
    public function __construct(
        string $jobName,
        string $lockDir = '/tmp',
        string $heartbeatFile = '/tmp/cron_heartbeat.log',
        ?LoggerInterface $logger = null
    ) {
        $this->jobName = $jobName;
        $this->heartbeatFile = $heartbeatFile;
        $this->lockFile = "{$lockDir}/{$jobName}.lock";
        $this->logger = $logger ?? new NullLogger();

        if (!is_dir($lockDir)) {
            mkdir($lockDir, 0755, true);
        }
    }

    public function getJobName(): string
    {
        return $this->jobName;
    }

    /**
     * Append an "alive" + timestamp to the heartbeat file.
     * Call this periodically in long‑running jobs to allow external monitoring.
     */
    public function touchHeartbeat(): void
    {
        file_put_contents($this->heartbeatFile, json_encode([
            'ts' => date('Y-m-d\TH:i:s\Z'),
            'job' => $this->jobName,
            'status' => 'ok',
            'duration_ms' => $this->endTimer(),
        ], JSON_PRETTY_PRINT) . PHP_EOL, FILE_APPEND);
    }

    /**
     * Attempt to acquire an exclusive lock for this job.
     *
     * @return bool `true` if the lock was successfully created, `false` if another instance is running
     */
    public function acquireLock(): bool
    {
        if (file_exists($this->lockFile)) {
            return false; // already running
        }
        file_put_contents($this->lockFile, getmypid());

        return true;
    }

    /**
     * Release the lock previously created by {@see acquireLock()}.
     * Safe to call even if the lock file has already been removed.
     */
    public function releaseLock(): void
    {
        if (file_exists($this->lockFile)) {
            unlink($this->lockFile);
        }
    }

    /**
     * Start execution timer.
     * Called before the workload begins.
     */
    public function startTimer(): void
    {
        $this->startTime = microtime(true);
    }

    /**
     * Stop the timer and return elapsed seconds since {@see startTimer()}.
     *
     * @return float seconds with millisecond precision
     */
    public function endTimer(): float
    {
        return round(microtime(true) - $this->startTime, 3);
    }

    /**
     * Return peak memory usage of this process.
     *
     * @return string Formatted string like "12.34 MB".
     */
    public function memoryUsage(): string
    {
        $mem = memory_get_peak_usage(true);

        return round($mem / 1048576, 2) . ' MB';
    }

    /**
     * Append a timestamped line to the per‑job log file.
     *
     * @param string $message message to write
     */
    public function log(string $message): void
    {
        $this->logger->info($message, ['job' => $this->jobName]);
    }
}

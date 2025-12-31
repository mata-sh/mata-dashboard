<?php

declare(strict_types=1);

namespace Watcher\Utils;

use Exception;

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
     * Directory where cronjob log files are written (no trailing slash).
     */
    protected string $logDir;

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
     * Configuration data injected via {@see setConfig()}.
     *
     * @var array<string,mixed>
     */
    protected array $config;

    /**
     * CronJob constructor.
     *
     * @param string $jobName       Name of the cronjob like "daily-report"
     * @param string $logDir        Directory for log files
     * @param string $lockDir       Directory for lock files
     * @param string $heartbeatFile File written by touchHeartbeat()
     */
    public function __construct(
        string $jobName,
        string $logDir = '/var/log/cronjobs',
        string $lockDir = '/tmp',
        string $heartbeatFile = '/tmp/cron_heartbeat.log'
    ) {
        $this->jobName = $jobName;
        $this->logDir = rtrim($logDir, '/');
        $this->heartbeatFile = $heartbeatFile;
        $this->lockFile = "{$lockDir}/{$jobName}.lock";

        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
        if (!is_dir($lockDir)) {
            mkdir($lockDir, 0755, true);
        }
        ini_set('error_log', $this->logDir . "/{$this->jobName}.log");
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
        file_put_contents($this->heartbeatFile, '[' . date('Y-m-d H:i:s') . "] {$this->jobName} alive\n", FILE_APPEND);
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
        $date = date('Y-m-d H:i:s');
        $msg = "[{$date}] {$this->jobName}: {$message}\n";
        file_put_contents("{$this->logDir}/{$this->jobName}.log", $msg, FILE_APPEND);
    }

    /**
     * Inject runtime configuration values.
     *
     * @param array<string,mixed> $config
     */
    private function setConfig(array $config): void
    {
        $this->config = $config;
    }

    /**
     * Loads app settings from a .env file.
     *
     * @param array<int,string>|string $filePaths the path to the file to be loaded
     *
     * @return array<string,mixed> Array keys are the setting names and the values are the values.
     *                             If a value contains commas, it's split into an array.
     *
     * @throws Exception if the environment file is not found at the specified path
     */
    public function loadConfigs(array|string $filePaths): array
    {
        if (!is_array($filePaths)) {
            $filePaths = [$filePaths];
        }

        foreach ($filePaths as $path) {
            $vars = [];
            if (!file_exists($path)) {
                throw new Exception('[ERROR] Environment file not found: ' . $path);
            }

            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                throw new Exception('[ERROR] Could not read environment file: ' . $path);
            }
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) {
                    continue; // Skip comments
                }
                [$name, $value] = explode('=', $line, 2);
                $value = trim($value);
                if (strpos($value, ',') !== false) {
                    // Split the value into an array if it contains commas
                    $value = array_map('trim', explode(',', $value));
                }

                if (!is_array($value)) {
                    $upperValue = strtoupper($value);
                    if ($upperValue === 'ON' || $value === 'true') {
                        $value = true;
                    }
                    if ($upperValue === 'OFF' || $value === 'false') {
                        $value = false;
                    }
                }
                if (is_numeric($value)) {
                    $value = (int) $value;
                }
                $vars[trim($name)] = $value;
            }
            $this->setConfig(array_merge($this->config, $vars));
        }

        return $this->config;
    }
}

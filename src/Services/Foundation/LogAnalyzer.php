<?php

declare(strict_types=1);

namespace Mata\Services\Foundation;

use Mata\Services\Collect\ServerDataCollectorInterface;
use Mata\Services\Event\EventManager;
use Mata\Utils\Tools;

/**
 * Application log analysis and processing service.
 *
 * Handles:
 * - Log file size monitoring and alarm evaluation
 * - Error and warning count analysis
 * - Raw data structure generation for rendering
 * - Fatal error detection and alarm triggering
 */
class LogAnalyzer
{
    private Tools $tools;
    private EventManager $eventManager;

    public function __construct(
        Tools $tools,
        EventManager $eventManager,
    ) {
        $this->tools = $tools;
        $this->eventManager = $eventManager;
    }

    /**
     * Analyze application logs and generate analysis data.
     *
     * @param array<string,mixed>          $appData             Application data from API
     * @param string                       $app                 Application name
     * @param string                       $server              Server name
     * @param ServerDataCollectorInterface $serverDataCollector Server API client
     * @param bool                         $alarmsEnabled       Whether alarms are enabled for this app
     *
     * @return array<string,mixed> Log analysis results
     */
    public function analyzeAppLogs(
        array $appData,
        string $app,
        string $server,
        ServerDataCollectorInterface $serverDataCollector,
        bool $alarmsEnabled
    ): array {
        // Analyze log file sizes
        $logSizeAnalysis = $this->analyzeLogFileSizes($appData['logs'], $app, $server, $alarmsEnabled);

        // Analyze error counts and generate status
        $errorAnalysis = $this->analyzeErrorCounts(
            $appData['status'],
            $app,
            $server,
            $serverDataCollector,
            $alarmsEnabled,
            $appData['logs']
        );

        return [
            'log_analysis' => $logSizeAnalysis,
            'error_analysis' => $errorAnalysis,
        ];
    }

    /**
     * Analyze log file sizes and check thresholds.
     *
     * @param array<string,int> $logs          Array containing log file data (path => size in KB)
     * @param string            $app           Application name
     * @param string            $server        Server name
     * @param bool              $alarmsEnabled Whether alarms are enabled for this app
     *
     * @return array<string,mixed> Log file size analysis results
     */
    private function analyzeLogFileSizes(array $logs, string $app, string $server, bool $alarmsEnabled): array
    {
        // $logs = json_decode($logsJson, true);
        $maxLogfileSize = 0;
        $maxLogFilePath = '';
        $firstLog = '';

        foreach ($logs as $log => $size) {
            $firstLog = $firstLog ?: $log;
            if ($size > $maxLogfileSize) {
                $maxLogfileSize = $size;
                $maxLogFilePath = htmlspecialchars($log);
            }
        }

        $level = $this->eventManager->getServerDataThresholdLevel(
            $maxLogfileSize,
            'log_filesize_kb'
        );

        if ($alarmsEnabled) {
            $this->evaluateLogSizeAlarm($maxLogfileSize, $maxLogFilePath, $level, $app, $server);
        }

        return [
            'max_size' => $maxLogfileSize,
            'max_size_formatted' => $this->tools->formatKilobytes($maxLogfileSize),
            'max_path' => $maxLogFilePath,
            'level' => $level,
            'first_log' => $firstLog,
            'files' => $logs,  // Raw log files data for rendering
        ];
    }

    /**
     * Analyze error counts and determine status.
     *
     * @param array<string,mixed>          $status              Status data containing error counts
     * @param string                       $app                 Application name
     * @param string                       $server              Server name
     * @param ServerDataCollectorInterface $serverDataCollector Server API client
     * @param bool                         $alarmsEnabled       Whether alarms are enabled
     * @param array<string,mixed>          $logs                Log file data array
     *
     * @return array<string,mixed> Error count analysis results
     */
    private function analyzeErrorCounts(
        array $status,
        string $app,
        string $server,
        ServerDataCollectorInterface $serverDataCollector,
        bool $alarmsEnabled,
        array $logs
    ): array {
        $fatalCount = $this->tools->formatCompactNumber($status['fatal']);
        $warningCount = $this->tools->formatCompactNumber($status['warnings']);
        $totalLineCount = $this->tools->formatCompactNumber($status['total']);

        $showEventsCount = 0;
        $showEventsType = 'working';
        $alarmsEnabledClass = '';

        if ($status['fatal'] > 0) {
            $showEventsCount = $fatalCount;
            $showEventsType = 'errors';

            if ($alarmsEnabled) {
                $alarmsEnabledClass = 'alarms-enabled';
                $this->evaluateFatalErrorAlarm($fatalCount, $app, $server);
            }
        } elseif ($status['warnings'] > 0) {
            $showEventsCount = $warningCount;
            $showEventsType = 'warnings';
        }

        // $logs = json_decode($logsJson, true);
        $firstLog = '';
        foreach ($logs as $log => $size) {
            $firstLog = $log;

            break;
        }

        return [
            'fatal_count' => $fatalCount,
            'warning_count' => $warningCount,
            'total_count' => $totalLineCount,
            'show_events_count' => $showEventsCount,
            'show_events_type' => $showEventsType,
            'alarms_enabled_class' => $alarmsEnabledClass,
            // Raw data for rendering
            'fatal_count_raw' => (int) $status['fatal'],
            'warning_count_raw' => (int) $status['warnings'],
            'total_count_raw' => (int) $status['total'],
            'first_log' => $firstLog,
        ];
    }

    /**
     * Evaluate log file size alarm conditions.
     *
     * @param int    $maxLogfileSize Maximum log file size in KB
     * @param string $maxLogFilePath Path to the largest log file
     * @param string $level          Threshold level for the log file size
     * @param string $app            Application name
     * @param string $server         Server name
     */
    private function evaluateLogSizeAlarm(
        int $maxLogfileSize,
        string $maxLogFilePath,
        string $level,
        string $app,
        string $server
    ): void {
        if ($level !== 'alarm') {
            return;
        }

        $maxLogfileSizeFormatted = $this->tools->formatKilobytes($maxLogfileSize);

        $this->eventManager->handleEvent(
            type: 'logfile_too_large',
            message: "Log too large ({$maxLogfileSizeFormatted}) - {$maxLogFilePath} : {$app} on Server: {$server}",
            level: 'critical',
            server: $server,
            app: $app
        );
    }

    /**
     * Evaluate fatal error alarm conditions.
     *
     * @param string $fatalCount Number of fatal errors as formatted string
     * @param string $app        Application name
     * @param string $server     Server name
     */
    private function evaluateFatalErrorAlarm(
        string $fatalCount,
        string $app,
        string $server
    ): void {
        $this->eventManager->handleEvent(
            type: 'fatal_errors_occurring',
            message: "Fatal Error Detected (Count: {$fatalCount}): {$app} on Server: {$server}",
            level: 'warning',
            server: $server,
            app: $app
        );
    }
}

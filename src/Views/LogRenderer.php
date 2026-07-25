<?php

declare(strict_types=1);

namespace Mata\Views;

/**
 * Log rendering service.
 *
 * Handles log-related view rendering:
 * - Log entry HTML formatting
 * - Process table generation
 * - Log data visualization
 * - Error and warning display formatting
 */
class LogRenderer extends BaseRenderer
{
    /**
     * Render log entries as HTML divs.
     *
     * @param array<string> $logData Array of log entries
     *
     * @return string HTML formatted log content
     */
    public function renderLogs(array $logData): string
    {
        $html = '';
        foreach ($logData as $row) {
            $html .= '<div>' . $this->escape($row) . '</div>';
        }

        return $html;
    }

    /**
     * Render PHP process data as HTML table.
     *
     * @param array<string,mixed> $phpProcessData Array of PHP process information
     *
     * @return string HTML formatted PHP process table
     */
    public function renderProcessTable(array $phpProcessData): string
    {
        $htmlRow = '';
        foreach ($phpProcessData as $row) {
            $user = $this->escape($row['user']);
            $pid = (int) $row['pid'];
            $ppid = (int) $row['ppid'];
            $cpu = $this->escape($row['cpu']);
            $mem = $this->escape($row['mem']);
            $etime = $this->escape($row['etime']);
            $args = $this->escape($row['args']);

            $htmlRow .= <<<HTML
                <tr>
                    <td>{$user}</td>
                    <td>{$pid}</td>
                    <td>{$ppid}</td>
                    <td>{$cpu}</td>
                    <td>{$mem}</td>
                    <td>{$etime}</td>
                    <td>{$args}</td>
                </tr>
        HTML;
        }

        return <<<HTML
            <table border="1" cellpadding="5" cellspacing="0">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>PID</th>
                        <th>PPID</th>
                        <th>%CPU</th>
                        <th>%MEM</th>
                        <th>Elapsed Time</th>
                        <th>Command</th>
                    </tr>
                </thead>
                <tbody>        
                    {$htmlRow}
                </tbody>
            </table>
        HTML;
    }

    /**
     * Format log analysis tooltip with file sizes.
     *
     * @param array<string,int> $logs Log files with their sizes
     *
     * @return string HTML formatted tooltip content
     */
    public function formatLogSizeTooltip(array $logs): string
    {
        $tooltip = '';
        foreach ($logs as $log => $size) {
            $tooltip .= sprintf(
                '%s <strong>%s</strong><br>',
                $this->escape($log),
                $this->tools->formatKilobytes((int) $size)
            );
        }

        return $tooltip;
    }

    /**
     * Format log analysis tooltip from server view data.
     *
     * @param array<string,mixed> $logAnalysisData Log analysis data from ServerViewBuilder
     *
     * @return string HTML formatted tooltip content
     */
    public function formatLogAnalysisTooltip(array $logAnalysisData): string
    {
        if (($logAnalysisData['logsize_monitoring_enabled'] ?? true) === false) {
            return 'Log size monitoring disabled';
        }

        if (isset($logAnalysisData['files']) && is_array($logAnalysisData['files'])) {
            return $this->formatLogSizeTooltip($logAnalysisData['files']);
        }

        return '';
    }

    /**
     * Format error status tooltip.
     *
     * The counts always describe what the node actually observed. Fatal-error
     * alarms can be switched off independently, so the alarm state is stated
     * separately instead of altering the reported counts.
     *
     * @param string $firstLog           Path to first log file
     * @param int    $fatalCount         Number of fatal errors
     * @param int    $warningCount       Number of warnings
     * @param int    $totalCount         Total line count
     * @param bool   $errorAlarmsEnabled Effective fatal-error alarm state
     * @param bool   $logAnalysisEnabled Per-app log analysis state
     *
     * @return string HTML formatted tooltip content
     */
    public function formatErrorStatusTooltip(
        string $firstLog,
        int $fatalCount,
        int $warningCount,
        int $totalCount,
        bool $errorAlarmsEnabled = true,
        bool $logAnalysisEnabled = true
    ): string {
        $fatalFormatted = $this->formatNumber($fatalCount);
        $warningFormatted = $this->formatNumber($warningCount);
        $totalFormatted = $this->formatNumber($totalCount);
        $alarmState = $this->formatErrorAlarmState($errorAlarmsEnabled);

        if (!$logAnalysisEnabled) {
            return <<< HTML
                <span style='font-weight: bold; color:gray'>Log analysis disabled</span><br>
                {$alarmState}
            HTML;
        }

        // Check if there are any errors or warnings
        if ($fatalCount === 0 && $warningCount === 0) {
            return <<< HTML
                {$this->escape($firstLog)}<br>
                <span style='font-weight: bold; color:green'>✓ Works fine!</span><br>
                {$totalFormatted} Total Lines<br>
                {$alarmState}
            HTML;
        }

        return <<< HTML
            {$this->escape($firstLog)}<br>
            <span style='color:red'>{$fatalFormatted} Errors</span> |
            <span style='color:orange'>{$warningFormatted} Warnings</span><br>
            {$totalFormatted} Total Lines<br>
            {$alarmState}
        HTML;
    }

    /**
     * Format error status tooltip from server view data.
     *
     * @param array<string,mixed> $errorAnalysisData Error analysis data from ServerViewBuilder
     *
     * @return string HTML formatted tooltip content
     */
    public function formatErrorAnalysisTooltip(array $errorAnalysisData): string
    {
        $errorAlarmsEnabled = ($errorAnalysisData['error_monitoring_enabled'] ?? true) !== false
            && ($errorAnalysisData['alarms_enabled'] ?? true) !== false;

        return $this->formatErrorStatusTooltip(
            firstLog: $errorAnalysisData['first_log'] ?? '',
            fatalCount: $errorAnalysisData['fatal_count_raw'] ?? 0,
            warningCount: $errorAnalysisData['warning_count_raw'] ?? 0,
            totalCount: $errorAnalysisData['total_count_raw'] ?? 0,
            errorAlarmsEnabled: $errorAlarmsEnabled,
            logAnalysisEnabled: ($errorAnalysisData['loganalysis_enabled'] ?? true) !== false,
        );
    }

    /** Render the fatal-error alarm state line of the error status tooltip. */
    private function formatErrorAlarmState(bool $enabled): string
    {
        return $enabled
            ? "Error Alarms: <span style='color:green'>On</span>"
            : "Error Alarms: <span style='color:gray'>Off</span>";
    }

    /**
     * Render log context for alarms (logs + process table).
     *
     * @param null|array<string>       $logData        Log entries
     * @param null|array<string,mixed> $phpProcessData Process data
     *
     * @return string HTML formatted context content
     */
    public function renderAlarmContext(?array $logData, ?array $phpProcessData): string
    {
        $context = '';

        if ($logData) {
            $context .= $this->renderLogs($logData) . '<br>';
        }

        if ($phpProcessData) {
            $context .= $this->renderProcessTable($phpProcessData);
        }

        return $context;
    }
}

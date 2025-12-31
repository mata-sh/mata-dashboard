<?php

declare(strict_types=1);

namespace Watcher\Views;

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
                $this->formatSize((int) $size)
            );
        }

        return $tooltip;
    }

    /**
     * Format log analysis tooltip from LogAnalyzer data.
     *
     * @param array<string,mixed> $logAnalysisData Log analysis data from LogAnalyzer
     *
     * @return string HTML formatted tooltip content
     */
    public function formatLogAnalysisTooltip(array $logAnalysisData): string
    {
        if (isset($logAnalysisData['files']) && is_array($logAnalysisData['files'])) {
            return $this->formatLogSizeTooltip($logAnalysisData['files']);
        }

        return '';
    }

    /**
     * Format error status tooltip.
     *
     * @param string $firstLog     Path to first log file
     * @param int    $fatalCount   Number of fatal errors
     * @param int    $warningCount Number of warnings
     * @param int    $totalCount   Total line count
     *
     * @return string HTML formatted tooltip content
     */
    public function formatErrorStatusTooltip(
        string $firstLog,
        int $fatalCount,
        int $warningCount,
        int $totalCount
    ): string {
        $fatalFormatted = $this->formatNumber($fatalCount);
        $warningFormatted = $this->formatNumber($warningCount);
        $totalFormatted = $this->formatNumber($totalCount);

        // Check if there are any errors or warnings
        if ($fatalCount === 0 && $warningCount === 0) {
            return <<< HTML
                {$this->escape($firstLog)}<br>
                <span style='font-weight: bold; color:green'>✓ Works fine!</span><br>
                {$totalFormatted} Total Lines
            HTML;
        }

        return <<< HTML
            {$this->escape($firstLog)}<br>
            <span style='color:red'>{$fatalFormatted} Errors</span> |
            <span style='color:orange'>{$warningFormatted} Warnings</span><br>
            {$totalFormatted} Total Lines
        HTML;
    }

    /**
     * Format error status tooltip from LogAnalyzer data.
     *
     * @param array<string,mixed> $errorAnalysisData Error analysis data from LogAnalyzer
     *
     * @return string HTML formatted tooltip content
     */
    public function formatErrorAnalysisTooltip(array $errorAnalysisData): string
    {
        return $this->formatErrorStatusTooltip(
            firstLog: $errorAnalysisData['first_log'] ?? '',
            fatalCount: $errorAnalysisData['fatal_count_raw'] ?? 0,
            warningCount: $errorAnalysisData['warning_count_raw'] ?? 0,
            totalCount: $errorAnalysisData['total_count_raw'] ?? 0,
        );
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

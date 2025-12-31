<?php

declare(strict_types=1);

namespace Watcher\Utils;

use InvalidArgumentException;

/**
 * Log file handler and parser utility.
 *
 * Provides functionality for reading and parsing log files:
 * - Log level filtering and extraction
 * - Line-by-line log file processing
 * - PSR-3 compliant log level support
 * - Memory-efficient file reading
 * - Log entry parsing and analysis
 * - Error and warning log extraction
 */
class LogHandler
{
    private string $filePath;

    /**
     * Initialize log handler with file path.
     *
     * @param string $filePath Path to the log file to read
     *
     * @throws InvalidArgumentException If log file doesn't exist
     */
    public function __construct(string $filePath)
    {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("Log file does not exist: {$filePath}");
        }

        $this->filePath = $filePath;
    }

    /**
     * Get all log entries matching a specific level.
     *
     * @param string $level Log level to filter by (case-insensitive)
     *
     * @return array<int,string> Array of log entries matching the level
     */
    public function getEntriesByLevel(string $level): array
    {
        $entries = [];
        $lines = file($this->filePath);

        if ($lines === false) {
            return [];
        }

        foreach ($lines as $line) {
            if (stripos($line, $level) !== false) {
                $entries[] = trim($line);
            }
        }

        return $entries;
    }

    /**
     * Get all supported PSR-3 log levels.
     *
     * @return array<int,string> Array of PSR-3 compliant log level names
     */
    public function getAllLevels(): array
    {
        return ['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];
    }
}

// // Example usage:
// $reader = new LogReader('path/to/your.log');
// $criticalLogs = $reader->getEntriesByLevel('CRITICAL');

// foreach ($criticalLogs as $log) {
//     echo $log . PHP_EOL;
// }

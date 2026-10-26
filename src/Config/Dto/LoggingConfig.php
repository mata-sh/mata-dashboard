<?php

declare(strict_types=1);

namespace Mata\Config\Dto;

/**
 * Immutable DTO representing logging configuration.
 *
 * Contains settings for application logging including log level and file location.
 */
final class LoggingConfig
{
    public const DEFAULT_CRON_HEARTBEAT_MAX_BYTES = 1_048_576;
    public const CRON_HEARTBEAT_MAX_BYTES_LOWER_BOUND = 131_072;
    public const CRON_HEARTBEAT_MAX_BYTES_UPPER_BOUND = 1_073_741_824;

    /**
     * @param int $cronHeartbeatMaxBytes Largest heartbeat file size; compaction keeps each file below it
     */
    public function __construct(
        public readonly string $filePath,
        public readonly string $level,
        public readonly int $cronHeartbeatMaxBytes = self::DEFAULT_CRON_HEARTBEAT_MAX_BYTES,
    ) {}
}

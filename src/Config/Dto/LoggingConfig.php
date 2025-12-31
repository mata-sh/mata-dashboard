<?php

declare(strict_types=1);

namespace Watcher\Config\Dto;

/**
 * Immutable DTO representing logging configuration.
 *
 * Contains settings for application logging including log levels,
 * methods, and file locations.
 */
final class LoggingConfig
{
    /**
     * @param array<int,string> $helperImportant List of important log level keywords
     * @param array<int,string> $methods         List of logging methods to use
     */
    public function __construct(
        public readonly array $helperImportant,
        public readonly bool $helperEnabled,
        public readonly array $methods,
        public readonly string $helperFile,
    ) {}
}
